<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fiscal:prune-conversations --days=30')
    ->dailyAt('03:00')
    ->withoutOverlapping();
