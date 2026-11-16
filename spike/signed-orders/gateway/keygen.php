<?php
// SPIKE(signed-orders): makes the Gateway's order key. The secret key never leaves the nested Gateway.
$pair = sodium_crypto_sign_keypair();
umask(0027);
@mkdir('/etc/orbit-spike', 0750);
file_put_contents('/etc/orbit-spike/gateway.key', base64_encode(sodium_crypto_sign_secretkey($pair)));
file_put_contents('/etc/orbit-spike/gateway.pub', base64_encode(sodium_crypto_sign_publickey($pair)));
chgrp('/etc/orbit-spike', 'orbit');
chgrp('/etc/orbit-spike/gateway.key', 'orbit');
echo "key written\n";
