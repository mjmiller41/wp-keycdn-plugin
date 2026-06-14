<?php
namespace KeyCDN\Offload\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StateMachine {

    const PENDING       = 'pending';
    const UPLOADING     = 'uploading';
    const VERIFYING     = 'verifying';
    const CONFIRMED     = 'confirmed';
    const LOCAL_REMOVED = 'local_removed';
    const QUARANTINED   = 'quarantined';
    const FAILED        = 'failed';

    private static array $transitions = [
        self::PENDING       => [ self::UPLOADING ],
        self::UPLOADING     => [ self::VERIFYING, self::FAILED ],
        self::VERIFYING     => [ self::CONFIRMED, self::QUARANTINED, self::FAILED ],
        self::CONFIRMED     => [ self::LOCAL_REMOVED, self::UPLOADING ],
        self::LOCAL_REMOVED => [],
        self::QUARANTINED   => [ self::UPLOADING ],
        self::FAILED        => [ self::UPLOADING ],
    ];

    public function can_transition( string $from, string $to ): bool {
        return isset( self::$transitions[ $from ] ) && in_array( $to, self::$transitions[ $from ], true );
    }

    public function transition( string $from, string $to ): void {
        if ( ! $this->can_transition( $from, $to ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Invalid state transition: %s → %s', $from, $to )
            );
        }
    }
}
