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
                ftp_close( $conn );
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
                ftp_close( $conn );
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
            CURLOPT_SSL_VERIFYPEER          => false,
            CURLOPT_SSL_VERIFYHOST          => 0,
            CURLOPT_TIMEOUT                 => 300,
            CURLOPT_RETURNTRANSFER          => true,
        ] );

        curl_exec( $ch );
        $err = curl_error( $ch );
        curl_close( $ch );
        fclose( $fh );

        if ( $err ) {
            throw new FtpException( "FTP upload failed for {$remote_path}: {$err}" );
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
     * List a remote directory.
     * 1. ftp_mlsd  — richest metadata, includes type (dir/file).
     * 2. ftp_rawlist — Unix ls -l format; first char 'd' = directory.
     * 3. ftp_nlist — last resort; no type info, everything treated as file.
     */
    public function list_dir( string $remote_dir ): array {
        $this->connect();

        if ( function_exists( 'ftp_mlsd' ) ) {
            $entries = @ftp_mlsd( $this->conn, $remote_dir );
            if ( false !== $entries ) {
                return $entries;
            }
        }

        $raw = @ftp_rawlist( $this->conn, $remote_dir );
        if ( false !== $raw && is_array( $raw ) ) {
            $entries = [];
            foreach ( $raw as $line ) {
                if ( empty( $line ) ) {
                    continue;
                }
                $parts = preg_split( '/\s+/', ltrim( $line ), 9 );
                if ( count( $parts ) < 9 ) {
                    continue;
                }
                $name = $parts[8];
                if ( in_array( $name, [ '.', '..' ], true ) ) {
                    continue;
                }
                $entries[] = [
                    'name' => $name,
                    'type' => str_starts_with( $parts[0], 'd' ) ? 'dir' : 'file',
                    'size' => (int) $parts[4],
                ];
            }
            return $entries;
        }

        $list = @ftp_nlist( $this->conn, $remote_dir );
        if ( false === $list ) {
            return [];
        }
        return array_map( fn( $name ) => [ 'name' => basename( $name ), 'type' => 'file' ], $list );
    }

}
