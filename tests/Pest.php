<?php

use SamPoyigi\Testbench\TestCase;

uses(TestCase::class)->in(__DIR__);

beforeEach(function(): void {
    config(['queue.default' => 'sync']);
})->in(__DIR__);
