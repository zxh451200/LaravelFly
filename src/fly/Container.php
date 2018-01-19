<?php

namespace Illuminate\Container;

use Closure;
use ArrayAccess;
use LogicException;
use ReflectionClass;
use ReflectionParameter;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container as ContainerContract;

class Container implements ArrayAccess, ContainerContract
{
    /**
     * The current globally available container (if any).
     *
     * @var static
     */
    protected static $instance;

    /**
     * An array of the types that have been resolved.
     *
     * @var array
     */
    protected $resolved = [];

    /**
     * The container's bindings.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * The container's method bindings.
     *
     * @var array
     */
    protected $methodBindings = [];

    /**
     * The container's shared instances.
     *
     * @var array
     */
    protected $instances = [];

    /**
     * The registered type aliases.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     *
     * @var array
     */
    protected $abstractAliases = [];

    /**
     * The extension closures for services.
     *
     * @var array
     */
    protected $extenders = [];

    /**
     * All of the registered tags.
     *
     * @var array
     */
    protected $tags = [];

    /**
     * The stack of concretions currently being built.
     *
     * @var array
     */
    protected $buildStack = [];

    /**
     * The parameter override stack.
     *
     * @var array
     */
    protected $with = [];

    /**
     * The contextual binding map.
     *
     * @var array
     */
    public $contextual = [];

    /**
     * All of the registered rebound callbacks.
     *
     * @var array
     */
    protected $reboundCallbacks = [];

    /**
     * All of the global resolving callbacks.
     *
     * @var array
     */
    protected $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     *
     * @var array
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * All of the resolving callbacks by class type.
     *
     * @var array
     */
    protected $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     *
     * @var array
     */
    protected $afterResolvingCallbacks = [];

    protected $arrayAttriForObj = ['resolved', 'bindings', 'methodBindings', 'instances', 'aliases', 'abstractAliases', 'extenders', 'tags', 'buildStack', 'with', 'contextual', 'reboundCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'];

    function __construct()
    {
        $this->initForCorontine(-1);
    }

    public function initForCorontine($cid)
    {
        if ($this->arrayAttriForObj) {
            foreach ($this->arrayAttriForObj as $attri) {
                $this->$attri[$cid] = $cid == -1 ? [] : $this->$attri[-1];
            }
        }
        if ($this->normalAttriForObj) {
            foreach ($this->normalAttriForObj as $attri => $defaultValue) {
                $this->$attri[$cid] = $cid == -1 ? $defaultValue : $this->$attri[-1];
            }

        }
    }

    function delForCoroutine(int $cid)
    {
        if ($this->arrayAttriForObj) {
            foreach ($this->arrayAttriForObj as $attri) {
                unset($this->$attri[$cid]);
            }
        }
        if ($this->normalAttriForObj) {
            foreach ($this->normalAttriForObj as $attri) {
                unset($this->$attri);
            }
        }
    }

    /**
     * Define a contextual binding.
     *
     * @param  string $concrete
     * @return \Illuminate\Contracts\Container\ContextualBindingBuilder
     */
    public function when($concrete)
    {
        return new ContextualBindingBuilder($this, $this->getAlias($concrete));
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param  string $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        $cid = \Swoole\Coroutine::getuid();
        return isset($this->bindings[$cid][$abstract]) ||
            isset($this->instances[$cid][$abstract]) ||
            $this->isAlias($abstract);
    }

    /**
     *  {@inheritdoc}
     */
    public function has($id)
    {
        return $this->bound($id);
    }

    /**
     * Determine if the given abstract type has been resolved.
     *
     * @param  string $abstract
     * @return bool
     */
    public function resolved($abstract)
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        $cid = \Swoole\Coroutine::getuid();
        return isset($this->resolved[$cid][$abstract]) ||
            isset($this->instances[$cid][$abstract]);
    }

    /**
     * Determine if a given type is shared.
     *
     * @param  string $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        $cid = \Swoole\Coroutine::getuid();
        return isset($this->instances[$cid][$abstract]) ||
            (isset($this->bindings[$cid][$abstract]['shared']) &&
                $this->bindings[$cid][$abstract]['shared'] === true);
    }

    /**
     * Determine if a given string is an alias.
     *
     * @param  string $name
     * @return bool
     */
    public function isAlias($name)
    {
        return isset($this->aliases[\Swoole\Coroutine::getuid()][$name]);
    }

    /**
     * Register a binding with the container.
     *
     * @param  string|array $abstract
     * @param  \Closure|string|null $concrete
     * @param  bool $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {

        $cid = \Swoole\Coroutine::getuid();
        // If no concrete type was given, we will simply set the concrete type to the
        // abstract type. After that, the concrete type to be registered as shared
        // without being forced to state their classes in both of the parameters.
        $this->dropStaleInstances($abstract, $cid);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // If the factory is not a Closure, it means it is just a class name which is
        // bound into this container to the abstract type and we will just wrap it
        // up inside its own Closure to give us more convenience when extending.
        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$cid][$abstract] = compact('concrete', 'shared');

        // If the abstract type was already resolved in this container we'll fire the
        // rebound listener so that any objects which have already gotten resolved
        // can have their copy of the object updated via the listener callbacks.
        if ($this->resolved($abstract)) {
            $this->rebound($abstract, $cid);
        }
    }

    /**
     * Get the Closure to be used when building a type.
     *
     * @param  string $abstract
     * @param  string $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->make($concrete, $parameters);
        };
    }

    /**
     * Determine if the container has a method binding.
     *
     * @param  string $method
     * @return bool
     */
    public function hasMethodBinding($method)
    {
        return isset($this->methodBindings[\Swoole\Coroutine::getuid()][$method]);
    }

    /**
     * Bind a callback to resolve with Container::call.
     *
     * @param  string $method
     * @param  \Closure $callback
     * @return void
     */
    public function bindMethod($method, $callback)
    {
        $this->methodBindings[\Swoole\Coroutine::getuid()][$method] = $callback;
    }

    /**
     * Get the method binding for the given method.
     *
     * @param  string $method
     * @param  mixed $instance
     * @return mixed
     */
    public function callMethodBinding($method, $instance)
    {
        return call_user_func($this->methodBindings[\Swoole\Coroutine::getuid()][$method], $instance, $this);
    }

    /**
     * Add a contextual binding to the container.
     *
     * @param  string $concrete
     * @param  string $abstract
     * @param  \Closure|string $implementation
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation)
    {
        $this->contextual[\Swoole\Coroutine::getuid()][$concrete][$this->getAlias($abstract)] = $implementation;
    }

    /**
     * Register a binding if it hasn't already been registered.
     *
     * @param  string $abstract
     * @param  \Closure|string|null $concrete
     * @param  bool $shared
     * @return void
     */
    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Register a shared binding in the container.
     *
     * @param  string|array $abstract
     * @param  \Closure|string|null $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * "Extend" an abstract type in the container.
     *
     * @param  string $abstract
     * @param  \Closure $closure
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->getAlias($abstract);

        $cid = \Swoole\Coroutine::getuid();

        if (isset($this->instances[$cid][$abstract])) {
            $this->instances[$cid][$abstract] = $closure($this->instances[$cid][$abstract], $this);

            $this->rebound($abstract, $cid);
        } else {
            $this->extenders[$cid][$abstract][] = $closure;

            if ($this->resolved($abstract)) {
                $this->rebound($abstract, $cid);
            }
        }
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @param  string $abstract
     * @param  mixed $instance
     * @return mixed
     */
    public function instance($abstract, $instance)
    {
        $cid = \Swoole\Coroutine::getuid();

        $this->removeAbstractAlias($abstract, $cid);

        $isBound = $this->bound($abstract);

        unset($this->aliases[$cid][$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container and it
        // can be updated with consuming classes that have gotten resolved here.
        $this->instances[$cid][$abstract] = $instance;

        if ($isBound) {
            $this->rebound($abstract, $cid);
        }

        return $instance;
    }

    /**
     * Remove an alias from the contextual binding alias cache.
     *
     * @param  string $searched
     * @return void
     */
    protected function removeAbstractAlias($searched, $cid)
    {
        if (!isset($this->aliases[$cid][$searched])) {
            return;
        }

        foreach ($this->abstractAliases[$cid] as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$cid][$abstract][$index]);
                }
            }
        }
    }

    /**
     * Assign a set of tags to a given binding.
     *
     * @param  array|string $abstracts
     * @param  array|mixed ...$tags
     * @return void
     */
    public function tag($abstracts, $tags)
    {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);

        $cid = \Swoole\Coroutine::getuid();

        foreach ($tags as $tag) {
            if (!isset($this->tags[$cid][$tag])) {
                $this->tags[$cid][$tag] = [];
            }

            foreach ((array)$abstracts as $abstract) {
                $this->tags[$cid][$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param  string $tag
     * @return array
     */
    public function tagged($tag)
    {
        $results = [];

        $cid = \Swoole\Coroutine::getuid();

        if (isset($this->tags[$cid][$tag])) {
            foreach ($this->tags[$cid][$tag] as $abstract) {
                $results[] = $this->make($abstract);
            }
        }

        return $results;
    }

    /**
     * Alias a type to a different name.
     *
     * @param  string $abstract
     * @param  string $alias
     * @return void
     */
    public function alias($abstract, $alias)
    {
        $cid = \Swoole\Coroutine::getuid();

        $this->aliases[$cid][$alias] = $abstract;

        $this->abstractAliases[$cid][$abstract][] = $alias;
    }

    /**
     * Bind a new callback to an abstract's rebind event.
     *
     * @param  string $abstract
     * @param  \Closure $callback
     * @return mixed
     */
    public function rebinding($abstract, Closure $callback)
    {
        $this->reboundCallbacks[$abstract = $this->getAlias($abstract)][] = $callback;

        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
    }

    /**
     * Refresh an instance on the given target and method.
     *
     * @param  string $abstract
     * @param  mixed $target
     * @param  string $method
     * @return mixed
     */
    public function refresh($abstract, $target, $method)
    {
        return $this->rebinding($abstract, function ($app, $instance) use ($target, $method) {
            $target->{$method}($instance);
        });
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     *
     * @param  string $abstract
     * @return void
     */
    protected function rebound($abstract, $cid)
    {
        $instance = $this->make($abstract);

        foreach ($this->getReboundCallbacks($abstract, $cid) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     *
     * @param  string $abstract
     * @return array
     */
    protected function getReboundCallbacks($abstract, $cid)
    {
        if (isset($this->reboundCallbacks[$cid][$abstract])) {
            return $this->reboundCallbacks[$cid][$abstract];
        }

        return [];
    }

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     *
     * @param  \Closure $callback
     * @param  array $parameters
     * @return \Closure
     */
    public function wrap(Closure $callback, array $parameters = [])
    {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string $callback
     * @param  array $parameters
     * @param  string|null $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        return BoundMethod::call($this, $callback, $parameters, $defaultMethod);
    }

    /**
     * Get a closure to resolve the given type from the container.
     *
     * @param  string $abstract
     * @return \Closure
     */
    public function factory($abstract)
    {
        return function () use ($abstract) {
            return $this->make($abstract);
        };
    }

    /**
     * An alias function name for make().
     *
     * @param  string $abstract
     * @param  array $parameters
     * @return mixed
     */
    public function makeWith($abstract, array $parameters = [])
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param  string $abstract
     * @param  array $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        return $this->resolve($abstract, \Swoole\Coroutine::getuid(), $parameters);
    }

    /**
     *  {@inheritdoc}
     */
    public function get($id)
    {
        if ($this->has($id)) {
            return $this->resolve($id, \Swoole\Coroutine::getuid());
        }

        throw new EntryNotFoundException;
    }

    /**
     * Resolve the given type from the container.
     *
     * @param  string $abstract
     * @param  array $parameters
     * @return mixed
     */
    protected function resolve($abstract, $cid, $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        $needsContextualBuild = !empty($parameters) || !is_null(
                $this->getContextualConcrete($abstract, $cid)
            );

        // If an instance of the type is currently being managed as a singleton we'll
        // just return an existing instance instead of instantiating new instances
        // so the developer can keep using the same objects instance every time.
        if (isset($this->instances[$cid][$abstract]) && !$needsContextualBuild) {
            return $this->instances[$cid][$abstract];
        }

        $this->with[$cid][] = $parameters;

        $concrete = $this->getConcrete($abstract, $cid);

        // We're ready to instantiate an instance of the concrete type registered for
        // the binding. This will instantiate the types, as well as resolve any of
        // its "nested" dependencies recursively until all have gotten resolved.
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        foreach ($this->getExtenders($abstract, $cid) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.
        if ($this->isShared($abstract) && !$needsContextualBuild) {
            $this->instances[$cid][$abstract] = $object;
        }

        $this->fireResolvingCallbacks($abstract, $object, $cid);

        // Before returning, we will also set the resolved flag to "true" and pop off
        // the parameter overrides for this build. After those two things are done
        // we will be ready to return back the fully constructed class instance.
        $this->resolved[$cid][$abstract] = true;

        array_pop($this->with[$cid]);

        return $object;
    }

    /**
     * Get the concrete type for a given abstract.
     *
     * @param  string $abstract
     * @return mixed   $concrete
     */
    protected function getConcrete($abstract, $cid)
    {
        if (!is_null($concrete = $this->getContextualConcrete($abstract, $cid))) {
            return $concrete;
        }

        // If we don't have a registered resolver or concrete for the type, we'll just
        // assume each type is a concrete name and will attempt to resolve it as is
        // since the container should be able to resolve concretes automatically.
        if (isset($this->bindings[$cid][$abstract])) {
            return $this->bindings[$cid][$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     *
     * @param  string $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract, $cid)
    {
        if (!is_null($binding = $this->findInContextualBindings($abstract, $cid))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        if (empty($this->abstractAliases[$cid][$abstract])) {
            return;
        }

        foreach ($this->abstractAliases[$cid][$abstract] as $alias) {
            if (!is_null($binding = $this->findInContextualBindings($alias, $cid))) {
                return $binding;
            }
        }
    }

    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     *
     * @param  string $abstract
     * @return string|null
     */
    protected function findInContextualBindings($abstract, $cid)
    {
        if (isset($this->contextual[$cid][end($this->buildStack[$cid])][$abstract])) {
            return $this->contextual[$cid][end($this->buildStack[$cid])][$abstract];
        }
    }

    /**
     * Determine if the given concrete is buildable.
     *
     * @param  mixed $concrete
     * @param  string $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Instantiate a concrete instance of the given type.
     *
     * @param  string $concrete
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function build($concrete)
    {
        $cid = \Swoole\Coroutine::getuid();

        // If the concrete type is actually a Closure, we will just execute it and
        // hand back the results of the functions, which allows functions to be
        // used as resolvers for more fine-tuned resolution of these objects.
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride($cid));
        }

        $reflector = new ReflectionClass($concrete);

        // If the type is not instantiable, the developer is attempting to resolve
        // an abstract type such as an Interface of Abstract Class and there is
        // no binding registered for the abstractions so we need to bail out.
        if (!$reflector->isInstantiable()) {
            return $this->notInstantiable($concrete, $cid);
        }

        $this->buildStack[$cid][] = $concrete;

        $constructor = $reflector->getConstructor();

        // If there are no constructors, that means there are no dependencies then
        // we can just resolve the instances of the objects right away, without
        // resolving any other types or dependencies out of these containers.
        if (is_null($constructor)) {
            array_pop($this->buildStack[$cid]);

            return new $concrete;
        }

        $dependencies = $constructor->getParameters();

        // Once we have all the constructor's parameters we can create each of the
        // dependency instances and then use the reflection instances to make a
        // new instance of this class, injecting the created dependencies in.
        $instances = $this->resolveDependencies(
            $dependencies, $cid
        );

        array_pop($this->buildStack[$cid]);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param  array $dependencies
     * @return array
     */
    protected function resolveDependencies(array $dependencies, $cid)
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            // If this dependency has a override for this particular build we will use
            // that instead as the value. Otherwise, we will continue with this run
            // of resolutions and let reflection attempt to determine the result.
            if ($this->hasParameterOverride($dependency, $cid)) {
                $results[] = $this->getParameterOverride($dependency, $cid);

                continue;
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            $results[] = is_null($dependency->getClass())
                ? $this->resolvePrimitive($dependency, $cid)
                : $this->resolveClass($dependency);
        }

        return $results;
    }

    /**
     * Determine if the given dependency has a parameter override.
     *
     * @param  \ReflectionParameter $dependency
     * @return bool
     */
    protected function hasParameterOverride($dependency, $cid)
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride($cid)
        );
    }

    /**
     * Get a parameter override for a dependency.
     *
     * @param  \ReflectionParameter $dependency
     * @return mixed
     */
    protected function getParameterOverride($dependency, $cid)
    {
        return $this->getLastParameterOverride($cid)[$dependency->name];
    }

    /**
     * Get the last parameter override.
     *
     * @return array
     */
    protected function getLastParameterOverride($cid)
    {
        return count($this->with[$cid]) ? end($this->with[$cid]) : [];
    }

    /**
     * Resolve a non-class hinted primitive dependency.
     *
     * @param  \ReflectionParameter $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolvePrimitive(ReflectionParameter $parameter, $cid)
    {
        if (!is_null($concrete = $this->getContextualConcrete('$' . $parameter->name, $cid))) {
            return $concrete instanceof Closure ? $concrete($this) : $concrete;
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $this->unresolvablePrimitive($parameter);
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @param  \ReflectionParameter $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        }

            // If we can not resolve the class instance, we will check to see if the value
            // is optional, and if it is we will return the optional parameter value as
            // the value of the dependency, similarly to how we do this with scalars.
        catch (BindingResolutionException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * Throw an exception that the concrete is not instantiable.
     *
     * @param  string $concrete
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function notInstantiable($concrete, $cid)
    {
        if (!empty($this->buildStack[$cid])) {
            $previous = implode(', ', $this->buildStack[$cid]);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new BindingResolutionException($message);
    }

    /**
     * Throw an exception for an unresolvable primitive.
     *
     * @param  \ReflectionParameter $parameter
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function unresolvablePrimitive(ReflectionParameter $parameter)
    {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new BindingResolutionException($message);
    }

    /**
     * Register a new resolving callback.
     *
     * @param  string $abstract
     * @param  \Closure|null $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        $cid = \Swoole\Coroutine::getuid();

        if (is_null($callback) && $abstract instanceof Closure) {
            $this->globalResolvingCallbacks[$cid][] = $abstract;
        } else {
            $this->resolvingCallbacks[$cid][$abstract][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     *
     * @param  string $abstract
     * @param  \Closure|null $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        $cid = \Swoole\Coroutine::getuid();

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[$cid][] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$cid][$abstract][] = $callback;
        }
    }

    /**
     * Fire all of the resolving callbacks.
     *
     * @param  string $abstract
     * @param  mixed $object
     * @return void
     */
    protected function fireResolvingCallbacks($abstract, $object, $cid)
    {

        $this->fireCallbackArray($object, $this->globalResolvingCallbacks[$cid]);

        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->resolvingCallbacks[$cid])
        );

        $this->fireAfterResolvingCallbacks($abstract, $object, $cid);
    }

    /**
     * Fire all of the after resolving callbacks.
     *
     * @param  string $abstract
     * @param  mixed $object
     * @return void
     */
    protected function fireAfterResolvingCallbacks($abstract, $object, $cid)
    {

        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks[$cid]);

        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->afterResolvingCallbacks[$cid])
        );
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param  string $abstract
     * @param  object $object
     * @param  array $callbacksPerType
     *
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType)
    {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param  mixed $object
     * @param  array $callbacks
     * @return void
     */
    protected function fireCallbackArray($object, array $callbacks)
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Get the container's bindings.
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings[\Swoole\Coroutine::getuid()];
    }

    /**
     * Get the alias for an abstract if available.
     *
     * @param  string $abstract
     * @return string
     *
     * @throws \LogicException
     */
    public function getAlias($abstract)
    {
        $cid = \Swoole\Coroutine::getuid();

        if (!isset($this->aliases[$cid][$abstract])) {
            return $abstract;
        }

        if ($this->aliases[$cid][$abstract] === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }

        return $this->getAlias($this->aliases[$cid][$abstract]);
    }

    /**
     * Get the extender callbacks for a given type.
     *
     * @param  string $abstract
     * @return array
     */
    protected function getExtenders($abstract, $cid)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->extenders[$cid][$abstract])) {
            return $this->extenders[$cid][$abstract];
        }

        return [];
    }

    /**
     * Remove all of the extender callbacks for a given type.
     *
     * @param  string $abstract
     * @return void
     */
    public function forgetExtenders($abstract)
    {
        unset($this->extenders[\Swoole\Coroutine::getuid()][$this->getAlias($abstract)]);
    }

    /**
     * Drop all of the stale instances and aliases.
     *
     * @param  string $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract, $cid)
    {

        unset($this->instances[$cid][$abstract], $this->aliases[$cid][$abstract]);
    }

    /**
     * Remove a resolved instance from the instance cache.
     *
     * @param  string $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        unset($this->instances[\Swoole\Coroutine::getuid()][$abstract]);
    }

    /**
     * Clear all of the instances from the container.
     *
     * @return void
     */
    public function forgetInstances()
    {
        $this->instances[\Swoole\Coroutine::getuid()] = [];
    }

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush()
    {
        $cid = \Swoole\Coroutine::getuid();
        $this->aliases[$cid] = [];
        $this->resolved[$cid] = [];
        $this->bindings[$cid] = [];
        $this->instances[$cid] = [];
        $this->abstractAliases[$cid] = [];
    }

    /**
     * Set the globally available instance of the container.
     *
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     *
     * @param  \Illuminate\Contracts\Container\Container|null $container
     * @return static
     */
    public static function setInstance(ContainerContract $container = null)
    {
        return static::$instance = $container;
    }

    /**
     * Determine if a given offset exists.
     *
     * @param  string $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->bound($key);
    }

    /**
     * Get the value at a given offset.
     *
     * @param  string $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->make($key);
    }

    /**
     * Set the value at a given offset.
     *
     * @param  string $key
     * @param  mixed $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        $this->bind($key, $value instanceof Closure ? $value : function () use ($value) {
            return $value;
        });
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string $key
     * @return void
     */
    public function offsetUnset($key)
    {
        $cid = \Swoole\Coroutine::getuid();

        unset($this->bindings[$cid][$key], $this->instances[$cid][$key], $this->resolved[$cid][$key]);
    }

    /**
     * Dynamically access container services.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     *
     * @param  string $key
     * @param  mixed $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this[\Swoole\Coroutine::getuid()][$key] = $value;
    }
}
