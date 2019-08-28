<?php

declare(strict_types=1);

namespace Jasny\SwitchRoute\Tests;

use BadMethodCallException;
use Closure;
use Jasny\ReflectionFactory\ReflectionFactory;
use Jasny\SwitchRoute\Invoker;
use Jasny\TestHelper;
use LogicException;
use PHPUnit\Framework\MockObject\Builder\InvocationMocker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * @covers \Jasny\SwitchRoute\Invoker
 */
class InvokerTest extends TestCase
{
    use TestHelper;
    use ExtendedClassesTrait;

    /**
     * Create reflection factory that will return a ReflectionMethod.
     *
     * @param array $invokable
     * @param bool $isStatic
     * @param ReflectionParameter[] $parameters
     * @return ReflectionFactory&MockObject
     */
    protected function createReflectionFactoryMock($invokable, $isStatic, $parameters): ReflectionFactory
    {
        $reflection = $this->createMock(ReflectionMethod::class);
        $reflection->expects($this->any())->method('isStatic')->willReturn($isStatic);
        $reflection->expects($this->any())->method('getParameters')->willReturn($parameters);

        $reflectionFactory = $this->createMock(ReflectionFactory::class);
        $reflectionFactory->expects($this->once())->method('reflectMethod')
            ->with(...$invokable)->willReturn($reflection);
        $reflectionFactory->expects($this->never())->method('reflectFunction');

        return $reflectionFactory;
    }


    public function invokableProvider()
    {
        return [
            ['foo', 'to-do', ['FooController', 'toDoAction'], "(new FooController)->toDoAction(%s)"],
            ['to-do', null, ['ToDoController', 'defaultAction'], "(new ToDoController)->defaultAction(%s)"],
            [null, 'qux', ['QuxAction', '__invoke'], "(new QuxAction)(%s)"],
        ];
    }

    /**
     * @dataProvider invokableProvider
     */
    public function test($controller, $action, $invokable, $expected)
    {
        $reflectionFactory = $this->createReflectionFactoryMock($invokable, false, []);
        $genArg = $this->createCallbackMock($this->never());

        $invoker = new Invoker(null, $reflectionFactory);
        $code = $invoker->generateInvocation(compact('controller', 'action'), $genArg);

        $this->assertEquals(sprintf($expected, ''), $code);
    }

    /**
     * @dataProvider invokableProvider
     */
    public function testWithArguments($controller, $action, $invokable, $expected)
    {
        $expectedCode = sprintf($expected, "\$var['id'] ?? NULL, \$var['some'] ?? NULL, \$var['good'] ?? 'ok'");

        $mixedType = $this->createMock(ReflectionType::class);
        $stringType = $this->createConfiguredMock(ReflectionNamedType::class, ['getName' => 'string']);
        $parameters = [
            $this->createConfiguredMock(
                ReflectionParameter::class,
                ['getName' => 'id', 'getType' => null, 'isOptional' => false]
            ),
            $this->createConfiguredMock(
                ReflectionParameter::class,
                ['getName' => 'some', 'getType' => $mixedType, 'isOptional' => false]
            ),
            $this->createConfiguredMock(
                ReflectionParameter::class,
                ['getName' => 'good', 'getType' => $stringType, 'isOptional' => true, 'getDefaultValue' => 'ok']
            ),
        ];
        $reflectionFactory = $this->createReflectionFactoryMock($invokable, false, $parameters);

        $genArg = $this->createCallbackMock($this->exactly(3), function (InvocationMocker $invoke) {
            $invoke
                ->withConsecutive(['id', null, null], ['some', null, null], ['good', 'string', 'ok'])
                ->willReturnOnConsecutiveCalls("\$var['id'] ?? NULL", "\$var['some'] ?? NULL", "\$var['good'] ?? 'ok'");
        });

        $invoker = new Invoker(null, $reflectionFactory);
        $code = $invoker->generateInvocation(compact('controller', 'action'), $genArg);

        $this->assertEquals($expectedCode, $code);
    }

    public function testOfStaticMethod()
    {
        $parameters = [
            $this->createConfiguredMock(ReflectionParameter::class, ['getName' => 'id', 'isOptional' => false]),
        ];
        $reflectionFactory = $this->createReflectionFactoryMock(['FooController', 'barAction'], true, $parameters);

        $genArg = $this->createCallbackMock($this->once(), ['id', '', null], "\$var['id'] ?? NULL");

        $invoker = new Invoker(null, $reflectionFactory);
        $code = $invoker->generateInvocation(['controller' => 'foo', 'action' => 'bar'], $genArg);

        $this->assertEquals("FooController::barAction(\$var['id'] ?? NULL)", $code);
    }

    /**
     * @dataProvider invokableProvider
     */
    public function testWithCustomClassName($controller, $action, $invokable, $expected)
    {
        $invokable[0] = 'App\\Generated\\' . $invokable[0];
        $expected = str_replace('new ', 'new App\\Generated\\', $expected);

        $createInvokable = function (?string $controller, ?string $action) {
            return $controller !== null
                ? [
                    'App\\Generated\\' . strtr(ucwords($controller, '-'), ['-' => '']) . 'Controller',
                    strtr(lcfirst(ucwords($action ?? 'default', '-')), ['-' => '']) . 'Action'
                ]
                : ['App\\Generated\\' . strtr(ucwords($action, '-'), ['-' => '']) . 'Action', '__invoke'];
        };

        $reflectionFactory = $this->createReflectionFactoryMock($invokable, false, []);
        $genArg = $this->createCallbackMock($this->never());

        $invoker = new Invoker($createInvokable, $reflectionFactory);
        $code = $invoker->generateInvocation(compact('controller', 'action'), $genArg);

        $this->assertEquals(sprintf($expected, ''), $code);
    }

    public function testWithFunction()
    {
        $parameters = [
            $this->createConfiguredMock(ReflectionParameter::class, ['getName' => 'id', 'isOptional' => false]),
        ];

        $reflection = $this->createMock(ReflectionFunction::class);
        $reflection->expects($this->any())->method('getParameters')->willReturn($parameters);

        $reflectionFactory = $this->createMock(ReflectionFactory::class);
        $reflectionFactory->expects($this->never())->method('reflectMethod');
        $reflectionFactory->expects($this->once())->method('reflectFunction')
            ->with()->willReturn($reflection);

        $genArg = $this->createCallbackMock($this->once(), ['id', '', null], "\$var['id'] ?? NULL");

        $createInvokable = function (?string $controller, ?string $action) {
            return $controller . '_' . str_replace('-', '', $action);
        };

        $invoker = new Invoker($createInvokable, $reflectionFactory);
        $code = $invoker->generateInvocation(['controller' => 'foo', 'action' => 'to-do'], $genArg);

        $this->assertEquals("foo_todo(\$var['id'] ?? NULL)", $code);
    }

    public function testWithMethodString()
    {
        $parameters = [
            $this->createConfiguredMock(ReflectionParameter::class, ['getName' => 'id', 'isOptional' => false]),
        ];
        $reflectionFactory = $this->createReflectionFactoryMock(['App\\Foo', 'toDo'], false, $parameters);

        $genArg = $this->createCallbackMock($this->once(), ['id', '', null], "\$var['id'] ?? NULL");

        $createInvokable = $this->createCallbackMock($this->once(), ['foo', 'to-do'], 'App\\Foo::toDo');

        $invoker = new Invoker($createInvokable, $reflectionFactory);
        $code = $invoker->generateInvocation(['controller' => 'foo', 'action' => 'to-do'], $genArg);

        $this->assertEquals("(new App\\Foo)->toDo(\$var['id'] ?? NULL)", $code);
    }

    public function invalidInvokerProvider()
    {
        $closure = function () {
        };

        return [
            [42, 'integer'],
            [$closure, 'Closure'],
            [[42, 'hello'], '[integer, string]'],
            [[new \stdClass(), 'foo'], '[stdClass, string]'],
            [['foo', 'bar', 'qux'], '[string, string, string]'],
        ];
    }

    /**
     * @dataProvider invalidInvokerProvider
     */
    public function testWithInvalidInvoker($invoker, $type)
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Invokable should be a function or array with class name and method, "
            . "{$type} given");

        $reflectionFactory = $this->createMock(ReflectionFactory::class);
        $reflectionFactory->expects($this->never())->method($this->anything());

        $genArg = $this->createCallbackMock($this->never());

        $createInvokable = $this->createCallbackMock($this->once(), ['foo', 'to-do'], $invoker);

        $invoker = new Invoker($createInvokable, $reflectionFactory);

        $invoker->generateInvocation(['controller' => 'foo', 'action' => 'to-do'], $genArg);
    }

    public function testWithNeitherControllerOrAction()
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage("Neither controller or action is set");

        $reflectionFactory = $this->createMock(ReflectionFactory::class);
        $reflectionFactory->expects($this->never())->method($this->anything());

        $genArg = $this->createCallbackMock($this->never());

        $invoker = new Invoker(null, $reflectionFactory);

        $invoker->generateInvocation(['controller' => null, 'action' => null], $genArg);
    }

    public function testGenerateDefault()
    {
        $expected = <<<CODE
if (\$allowedMethods === []) {
    http_response_code(404);
    echo "Not Found";
} else {
    http_response_code(405);
    header('Allow: ' . join(', ', \$allowedMethods));
    echo "Method Not Allowed";
}
CODE;

        $reflectionFactory = $this->createMock(ReflectionFactory::class);
        $invoker = new Invoker(null, $reflectionFactory);

        $this->assertEquals($expected, $invoker->generateDefault());
    }

    public function testCreateInvokable()
    {
        self::assertSame(['DummyController', 'defaultAction'], Invoker::createInvokable('Dummy', null));
    }

    /** test Invoker protected members */

    protected function setUp(): void
    {
        parent::setUp();
        $this->initExtendedInvoker();
    }

    public function testGenerateInvocationArgs()
    {
        $reflection = new ReflectionFunction('time');
        $genArg = function () {
        };
        self::assertEmpty($this->extendedInvoker->callGenerateInvocationArgs($reflection, $genArg));
    }

    public function testGenerateInvocationMethod()
    {
        $invokable = ['DateTime', 'format'];
        $reflection = new ReflectionMethod('DateTime', 'format');
        $new = '(new \\%s)';
        self::assertSame('(new \DateTime)->format', $this->extendedInvoker->callGenerateInvocationMethod($invokable, $reflection, $new));
    }

    public function testAssertInvokable()
    {
        $invokable = ['DateTime', 'format'];
        self::assertNull($this->extendedInvoker->callAssertInvokable($invokable));
    }

    public function testGetReflection()
    {
        $invokable = ['DateTime', 'format'];
        $reflection = $this->extendedInvoker->callGetReflection($invokable);
        self::assertInstanceOf(ReflectionMethod::class, $reflection);
        self::assertTrue(isset($reflection->name));
        self::assertSame('format', $reflection->name);
        self::assertTrue(isset($reflection->class));
        self::assertSame('DateTime', $reflection->class);
    }

    public function testReflectionProperty()
    {
        self::assertInstanceOf(ReflectionFactory::class, $this->extendedInvoker->getReflectionProperty());
    }

    public function testCreateInvokableProperty()
    {
        self::assertInstanceOf(Closure::class, $this->extendedInvoker->getCreateInvokableProperty());
    }
}
