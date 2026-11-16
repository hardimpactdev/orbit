<?php
// SPIKE(signed-orders): a PHP worker holding 250 MB.
$keep = [];
for ($i = 0; $i < 250; $i++) {
    $keep[] = str_repeat(chr($i % 256), 1 << 20);
}
while (true) {
    sleep(3600);
}
