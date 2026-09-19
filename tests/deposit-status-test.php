<?php
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-deposit.php';

$failed = 0;
function pawapay_assert( $ok, $message ) {
    global $failed;
    if ( $ok ) {
        echo "OK: $message\n";
        return;
    }
    $failed++;
    fwrite( STDERR, "FAIL: $message\n" );
}

pawapay_assert( WC_PawaPay_Deposit::extract_status( [ 'status' => 'COMPLETED' ] ) === 'COMPLETED', 'flat status' );
pawapay_assert( WC_PawaPay_Deposit::extract_status( [ [ 'status' => 'failed' ] ] ) === 'FAILED', 'list status' );
pawapay_assert( WC_PawaPay_Deposit::extract_status( [ 'data' => [ 'status' => 'Accepted' ] ] ) === 'ACCEPTED', 'nested status' );
pawapay_assert( WC_PawaPay_Deposit::extract_deposit_payload( [ [ 'depositId' => 'abc' ] ] )['depositId'] === 'abc', 'list payload' );

$poll = [ [
    'status'           => 'COMPLETED',
    'requestedAmount'  => '25',
    'depositedAmount'  => '100',
] ];
pawapay_assert( WC_PawaPay_Deposit::extract_amount( $poll, 'requestedAmount' ) === '25', 'requested amount from poll' );
pawapay_assert( WC_PawaPay_Deposit::extract_amount( $poll, 'depositedAmount' ) === '100', 'deposited amount from poll' );

exit( $failed === 0 ? 0 : 1 );
