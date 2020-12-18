<?php

use module\lib\EventManager;
use module\tests\App;
use module\tests\AppListener;

require '../bootstrap.php';

$em = new EventManager();

$groupListener = new AppListener();

// register a group listener
$em->attach('app', $groupListener);

// all `app.` prefix events will be handled by `AppListener::allEvent()`
$em->attach('app.*', [$groupListener, 'allEvent']);

// create app
$app = new App($em);

// run.
$app->run();
