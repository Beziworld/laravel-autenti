<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\Autenti')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\Autenti\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\Autenti\Exceptions')
    ->toExtend('Beziworld\Autenti\Exceptions\AutentiException')
    ->ignoring('Beziworld\Autenti\Exceptions\AutentiException');
