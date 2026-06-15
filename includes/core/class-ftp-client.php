<?php
namespace KeyCDN\Offload\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FtpException extends \RuntimeException {}

class FtpClient {

    private Credentials $credentials;

    /** @var resource|false */
    private $conn = false;


    public function __construct( Credentials $credentials ) {
        $this->credentials = $credentials;
    }

    public function connect(): void {
        if ( $this->conn ) {
            return;
        }
        $host        = $this->credentials->get_ftp_host();
        $max_retries = 3;

        for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
            $conn = @ftp_ssl_connect( $host, 21, 30 );
            if ( false === $conn ) {
                if ( $attempt < $max_retries ) {
                    sleep( 3 );
                    continue;
                }
                throw new FtpException( "Could not connect to FTP host: {$host}" );
            }

            $user        = $this->credentials->get_ftp_user();
            $pass        = $this->credentials->get_ftp_pass();
            $ftp_warning = null;
            set_error_handler( function ( $errno, $errstr ) use ( &$ftp_warning ) {
                $ftp_warning = $errstr;
                return true;
            } );
            $login_ok = ftp_login( $conn, $user, $pass );
            restore_error_handler();

            if ( ! $login_ok ) {
                @ftp_close( $conn );
                if ( $ftp_warning && strpos( $ftp_warning, '421' ) !== false ) {
                    if ( $attempt < $max_retries ) {
                        sleep( 3 );
                        continue;
                    }
                    throw new FtpException(
                        'FTP connection limit reached — KeyCDN allows max 3 simultaneous connections per subuser. ' .
                        'Check your KeyCDN dashboard for stuck sessions, or wait a few minutes and try again.'
                    );
                }
                throw new FtpException( 'FTP login failed. Check subuser credentials.' );
            }

            if ( ! @ftp_pasv( $conn, true ) ) {
                @ftp_close( $conn );
                throw new FtpException( 'Failed to enable passive mode.' );
            }
            // Needed for FTPS behind NAT — prevents server returning its internal IP.
            @ftp_set_option( $conn, FTP_USEPASVADDRESS, false );
            $this->conn = $conn;
            return;
        }
    }

    public function disconnect(): void {
        if ( $this->conn ) {
            @ftp_close( $this->conn );
            $this->conn = false;
        }
    }

    public function __destruct() {
        $this->disconnect();
    }

    /**
     * Upload a local file to a remote path via cURL.
     * Uses cURL instead of PHP's ftp_put() because ftp_put() hangs on data-channel
     * writes in Docker/NAT environments despite the control channel working fine.
     * cURL handles FTPS data channels correctly and creates missing directories.
     */
    public function put( string $local_path, string $remote_path ): void {
        $fh = fopen( $local_path, 'rb' );
        if ( ! $fh ) {
            throw new FtpException( "Cannot open local file for reading: {$local_path}" );
        }

        $ch = curl_init();
        curl_setopt_array( $ch, [
            CURLOPT_URL                     => 'ftp://' . $this->credentials->get_ftp_host() . $remote_path,
            CURLOPT_USERPWD                 => $this->credentials->get_ftp_user() . ':' . $this->credentials->get_ftp_pass(),
            CURLOPT_UPLOAD                  => true,
            CURLOPT_INFILE                  => $fh,
            CURLOPT_INFILESIZE              => (int) filesize( $local_path ),
            CURLOPT_FTP_CREATE_MISSING_DIRS => true,
            CURLOPT_USE_SSL                 => CURLUSESSL_ALL,
            CURLOPT_FTPSSLAUTH              => CURLFTPAUTH_TLS,
            CURLOPT_TIMEOUT                 => 300,
            CURLOPT_RETURNTRANSFER          => true,
        ] );

        $result   = curl_exec( $ch );
        $err      = curl_error( $ch );
        $ftp_code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        curl_close( $ch );
        fclose( $fh );

        if ( false === $result || $err ) {
            throw new FtpException( "FTP upload failed for {$remote_path}: " . ( $err ?: 'unknown cURL error' ) );
        }
        if ( $ftp_code >= 400 ) {
            throw new FtpException( "FTP upload rejected for {$remote_path}: server returned {$ftp_code}" );
        }
    }

    /**
     * Verify upload by comparing remote size with local filesize.
     */
    public function verify( string $remote_path, int $expected_bytes ): bool {
        $this->connect();
        $remote_size = @ftp_size( $this->conn, $remote_path );
        if ( $remote_size < 0 ) {
            // Control channel may have gone idle during a long cURL upload; reconnect and retry once.
            $this->disconnect();
            $this->connect();
            $remote_size = @ftp_size( $this->conn, $remote_path );
        }
        if ( $remote_size < 0 ) {
            return false;
        }
        if ( $expected_bytes > 0 && $remote_size === 0 ) {
            return false;
        }
        return $remote_size === $expected_bytes;
    }

    public function delete( string $remote_path ): void {
        $this->connect();
        // ftp_delete returns false if file doesn't exist — treat as success (already gone).
        @ftp_delete( $this->conn, $remote_path );
    }

    /**
     * List a remote directory via cURL MLSD, with NLST fallback.
     * Uses cURL (not PHP ftp_* functions) for the same reason put() does: PHP's
     * ftp_* data-channel operations hang in Docker/NAT environments.
     */
    public function list_dir( string $remote_dir ): array {
        $path = trim( $remote_dir, '/' );
        $url  = 'ftp://' . $this->credentials->get_ftp_host() . '/' . ( $path ? $path . '/' : '' );

        $base_opts = [
            CURLOPT_USERPWD        => $this->credentials->get_ftp_user() . ':' . $this->credentials->get_ftp_pass(),
            CURLOPT_USE_SSL        => CURLUSESSL_ALL,
            CURLOPT_FTPSSLAUTH     => CURLFTPAUTH_TLS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        // Try MLSD — richest metadata (type, size).
        $ch = curl_init( $url );
        curl_setopt_array( $ch, $base_opts + [ CURLOPT_CUSTOMREQUEST => 'MLSD' ] );
        $output = curl_exec( $ch );
        $err    = curl_error( $ch );
        curl_close( $ch );

        if ( $output && ! $err ) {
            return $this->parse_mlsd( (string) $output );
        }

        // Fall back to NLST — just filenames, no type info.
        $ch = curl_init( $url );
        curl_setopt_array( $ch, $base_opts + [ CURLOPT_FTPLISTONLY => true ] );
        $output = curl_exec( $ch );
        $err    = curl_error( $ch );
        curl_close( $ch );

        if ( false === $output || $err ) {
            return [];
        }

        $names = array_filter( array_map( 'trim', explode( "\n", (string) $output ) ) );
        return array_map( fn( $n ) => [ 'name' => basename( $n ), 'type' => 'file' ], array_values( $names ) );
    }

    /** Parse an MLSD response into the same [ 'name', 'type', 'size' ] shape used elsewhere. */
    private function parse_mlsd( string $output ): array {
        $entries = [];
        foreach ( explode( "\n", $output ) as $line ) {
            $line = trim( $line );
            if ( ! $line ) {
                continue;
            }
            $sep = strrpos( $line, ' ' );
            if ( false === $sep ) {
                continue;
            }
            $name = substr( $line, $sep + 1 );
            if ( in_array( $name, [ '.', '..' ], true ) ) {
                continue;
            }
            $facts = [];
            foreach ( explode( ';', substr( $line, 0, $sep ) ) as $fact ) {
                if ( strpos( $fact, '=' ) === false ) {
                    continue;
                }
                [ $k, $v ]          = explode( '=', $fact, 2 );
                $facts[ strtolower( trim( $k ) ) ] = trim( $v );
            }
            $entries[] = [
                'name' => $name,
                'type' => $facts['type'] ?? 'file',
                'size' => (int) ( $facts['size'] ?? 0 ),
            ];
        }
        return $entries;
    }

}
