<?php


namespace LaravelFly\Tests\Map\Unit;

use LaravelFly\Tests\BaseTestCase as Base;

class IncludeFlyFilesTest extends Base
{

    function testInitEnv()
    {
        $r = self::processGetArray(function () {
            $r[] = class_exists('Illuminate\Container\Container', false);
            $r[] = class_exists('Illuminate\Foundation\Application ', false);

            $options = ['mode' => 'Map', 'log_cache' => 2];

            \LaravelFly\Server\Common::$flyBaseDir = LARAVEL_APP_ROOT. '/vendor/scil/laravel-fly-files/src/';
            \LaravelFly\Server\Common::includeFlyFiles($options);


            $r[] = class_exists('Illuminate\Foundation\Application', false);
            return $r;
        });

        self::assertEquals([true,false,true], $r);
    }

}