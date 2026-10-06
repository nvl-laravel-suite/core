<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Nvl\Support\Bindings\RequiredBindingDefinition;
use Nvl\Support\Bindings\RequiredBindings;
use Nvl\Support\Doctor\RequiredBindingsDoctor;
use Nvl\Support\Tests\Fixtures\C4OrderBindingContract;
use Nvl\Support\Tests\Fixtures\C4OrderBindingPlaceholder;
use Nvl\Support\Tests\Fixtures\C4TaskBindingContract;
use Nvl\Support\Tests\Fixtures\C4TaskBindingPlaceholder;

it('inspects native placeholder and throwing closure registrations without executing factories', function (): void {
    $container = new Container;
    $config = new Repository(['nvl-payments' => ['enabled' => true]]);
    $bindings = new RequiredBindings($container, $config);
    $contract = C4OrderBindingContract::class;
    $placeholder = C4OrderBindingPlaceholder::class;
    $definition = new RequiredBindingDefinition('payments', $contract, $placeholder, 'order_resolution', 'nvl-payments.enabled', 'README.md#host-bindings');
    $bindings->register($definition);
    $container->bindIf($contract, $placeholder);
    expect($bindings->inspect()[0]->status)->toBe('missing');
    $calls = 0;
    $container->bind($contract, function () use (&$calls): never {
        $calls++;
        throw new RuntimeException('factory executed');
    });
    expect($bindings->inspect()[0]->status)->toBe('configured')->and($calls)->toBe(0);
    $container->instance($contract, new $placeholder);
    expect($bindings->inspect()[0]->status)->toBe('missing');
    $config->set('nvl-payments.enabled', false);
    expect($bindings->inspect()[0]->status)->toBe('inactive');
});

it('retains host precedence before or after bindIf and serialized config enablement', function (bool $hostFirst): void {
    $container = new Container;
    $contract = C4TaskBindingContract::class;
    $placeholder = C4TaskBindingPlaceholder::class;
    $host = function (): never {
        throw new RuntimeException('must not execute');
    };
    if ($hostFirst) {
        $container->bind($contract, $host);
    }
    $container->bindIf($contract, $placeholder);
    if (! $hostFirst) {
        $container->bind($contract, $host);
    }
    $config = new Repository(unserialize(serialize(['nvl-tasks' => ['routes' => ['management' => ['enabled' => true]]]])));
    $bindings = new RequiredBindings($container, $config);
    $bindings->register(new RequiredBindingDefinition('tasks', $contract, $placeholder, 'user_task_mutation', null, 'README.md#host-bindings'));
    expect($bindings->inspect()[0]->status)->toBe('configured');
})->with([true, false]);

it('orders definitions rejects conflicts and distinguishes enabled from dormant Doctor requirements', function (): void {
    $bindings = new RequiredBindings(new Container, new Repository(['nvl-payments' => ['enabled' => true]]));
    $task = new RequiredBindingDefinition('tasks', C4TaskBindingContract::class, C4TaskBindingPlaceholder::class, 'user_task_mutation', null, 'README.md#host-bindings');
    $payment = new RequiredBindingDefinition('payments', C4OrderBindingContract::class, C4OrderBindingPlaceholder::class, 'order_resolution', 'nvl-payments.enabled', 'README.md#host-bindings');
    $bindings->register($task);
    $bindings->register($payment);
    $bindings->register($task);
    expect($bindings->all())->toBe([$payment, $task])->and($bindings->all('tasks'))->toBe([$task]);
    $checks = iterator_to_array((new RequiredBindingsDoctor($bindings))->inspect());
    expect($checks[0]->severity)->toBe('error')->and($checks[0]->passed)->toBeFalse()
        ->and($checks[1]->severity)->toBe('info')->and($checks[1]->passed)->toBeTrue();
    expect(fn () => $bindings->register(new RequiredBindingDefinition('tasks', $task->contract, $task->placeholder, 'different', null, 'README.md')))
        ->toThrow(InvalidArgumentException::class);
});
