<?php
/** Test message from bin/send-test-email.php. Variables: $sentAt, $e (escaper). */
?>
<h1 style="margin:0 0 12px;font-size:20px;">Email settings work.</h1>
<p style="margin:0 0 12px;">This test message was sent by the PK Apparel website at <?= $e($sentAt) ?>.</p>
<p style="margin:0;color:#5c6170;">Inquiry notifications and replies to buyers will be sent the same way.</p>
