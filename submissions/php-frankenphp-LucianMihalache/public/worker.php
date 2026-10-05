<?php

/*
| The whole application. FrankenPHP starts this script once in each worker and keeps it running: everything above
| the loop happens once, and the loop body runs for every request. There is no framework and no package.
*/

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Jwt.php';
require __DIR__ . '/../src/Feed.php';

ignore_user_abort(true);

$answer = static function (): void {
    Feed::answer();
};

while (frankenphp_handle_request($answer)) {
    // one request answered; wait for the next
}
