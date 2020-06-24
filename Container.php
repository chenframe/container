<?php
namespace Chenframe\Container;
use Chenframe\Promise\Container\Container as ContainerPromise;

class Container implements \ArrayAccess
{

    //将已经实例化的对象绑定到容器，
    //例如：$api = new HelpSpot\API(new HttpClient);
    // $this->app->instance('HelpSpot\Api', $api);
    //效果图
    //［
    //     'HelpSpot\Api' => $api//$api是API类的对象，这里简写了
    // ］
    protected $instances = [];

    //存储类别名
    protected $aliases = [];

    //注册的别名以抽象名称为键，注册 $this->abstractAliases[$abstract][] = $alias;，及一个抽象对象可以有多个别名相对应
    protected $abstractAliases = [];

    //容器绑定的类
    protected $bindings = [];

    //上下文绑定映射
    public $contextual = [];

    //当前实例化时的堆栈
    protected $buildStack = [];

    //参数覆盖堆栈
    protected $with = [];


    //注册一个现有的实例，到容器
    public function instance($abstract, $instance)
    {
        //先移除抽象类别名
        $this->removeAbstractAlias($abstract);

        //判断是否已经绑定
        $isBound = $this->bound($abstract);

        //删除别名组数据
        unset($this->aliases[$abstract]);

        //先注册实例到 共享instances 数组里
        $this->instances[$abstract] = $instance;

        //如果已经绑定，则重新绑定一次
        if($isBound)
        {
            $this->rebound($abstract);
        }

        return $instance;
    }


    //移除抽象类别名
    public function removeAbstractAlias($searched)
    {
        //如果别名数组不存在，则直接返回
        if(! isset($this->aliases[$searched]))
        {
            return ;
        }
        //别名数组存在，则查找 注册的别名以抽象名称为键 列表
        foreach ($this->abstractAliases as $abstract => $aliases)
        {
            foreach ($aliases as $index => $alias)
            {
                if($alias == $searched)
                {
                    unset($this->abstractAliases[$abstract[$index]]);
                }
            }
        }

    }

    //检测改类是否已经绑定过
    public function bound($abstract)
    {
        return
            isset($this->bindings[$abstract]) ||            //判断是否已经绑定
            isset($this->instances[$abstract]) ||           //判断是否已经共享
            $this->isAlias($abstract);                      //判断是否已经绑定到别名组
    }


    //检测给出的类型是否已经绑定到别名
    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
    }

    //重新绑定
    public function rebound($abstract)
    {
        //先进行实例化
        $instance = $this->make($abstract);

    }


    //实例化
    public function make($abstract, array $parameters = [])
    {
        return $this->resolve($abstract,$parameters);
    }


    //从容器中解析出实例
    public function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        //获取别名，可能是别名数组
        $abstract = $this->getAlias($abstract);

        //获取上下文依赖实例
        $concrete = $this->getContextualConcrete($abstract);

        //参数不为空 或者 实例 不为null
        $needsContextualBuild = ! empty($parameters) || ! is_null($concrete);

        //已经实例化，并且上下文依赖为空 则直接返回实例，singleton 直接返回
        if(isset($this->instances[$abstract]) && ! $needsContextualBuild)
        {
            return $this->instances[$abstract];
        }

        //参数赋值
        $this->with[] = $parameters;

        //判断上下文实例
        if(is_null($concrete))
        {
            $concrete = $this->getConcrete($abstract);
        }

        // concrete == abstract 或者 concrete 是匿名函数 则直接 build
        if($this->isBuildable($concrete, $abstract))
        {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

    }

    //实例和抽象一样 实例是闭包
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof \Closure;
    }

    //实例化
    public function build($concrete)
    {
        //如果实例时闭包，则直接返回
        if($concrete instanceof \Closure)
        {
            return $concrete($this,$this->getLastParameterOverride());
        }

        //不是闭包函数，则通过反射获取
        try{
            $reflector = new \ReflectionClass($concrete);
        }catch (\ReflectionException $e){
            throw $e;
        }

        if(! $reflector->isInstantiable())
        {
            return $this->notInstantiable($concrete);
        }

        $this->buildStack[] = $concrete;

        $constructor = $reflector->getConstructor();

        //如果构造函数是空（及没有依赖其他函数） 则可以直接new 返回，
        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }

        //构造函数不为空，则有相关的依赖产生
        $dependencies = $constructor->getParameters();

        //一但获取到构造函数的依赖，我们就可以提供反射 实例化每个依赖函数
        try {
            $instances = $this->resolveDependencies($dependencies);
        } catch (\Exception $e) {
            array_pop($this->buildStack);

            throw $e;
        }
        // TODO


    }

    //解析依赖函数
    protected function resolveDependencies(array $dependencies)
    {
        $results = [];

        foreach ($dependencies as $dependency) {
          //判断依赖的值 是否存在，如果存在则取出值
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            $result = is_null($dependency->getClass())
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency);

            if ($dependency->isVariadic()) {
                $results = array_merge($results, $result);
            } else {
                $results[] = $result;
            }
        }

        return $results;
    }

    //判断依赖函数是否存在
    protected function hasParameterOverride($dependency)
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride()
        );
    }

    //去除依赖的值
    protected function getParameterOverride($dependency)
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }


    //不能实例化
    protected function notInstantiable($concrete)
    {
        if(! empty($this->buildStack))
        {
            $previous = implode(', ', $this->buildStack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new \Exception($message);
    }


    protected function getLastParameterOverride()
    {
        return count($this->with) ? end($this->with) : [];
    }


    //获取实例
    protected function getConcrete($abstract)
    {
        if(isset($this->bindings[$abstract]))
        {
            return $this->bindings[$abstract]["concrete"];
        }

        return $abstract;
    }


    //获取别名
    public function getAlias($abstract)
    {
        //如果别名数组不存在，则返回$abstract 自生
        if(! isset($this->aliases[$abstract]))
        {
            return $abstract;
        }
        //否则 返回 该抽象类的别名数组
        return $this->getAlias($this->aliases[$abstract]);
    }

    //获取上下文依赖实例
    public  function getContextualConcrete($abstract)
    {
        //上下文依赖关系不为空则直接返回
        if(! is_null($binding = $this->findInContextualBindings($abstract)))
        {
            return $binding;
        }

        //再判断是否已经绑定到别名列表中
        if(empty($this->abstractAliases[$abstract]))
        {
            //别名列表为空，则直接返回
            return;
        }

        //别名列表不为空
        foreach ($this->abstractAliases[$abstract] as $alias)
        {
            if(! is_null($binding = $this->findInContextualBindings($alias)))
            {
                return $binding;
            }
        }

    }

    //查找在上下文依赖的数组
    public function findInContextualBindings($abstract)
    {
        //end() 函数将内部指针指向数组中的最后一个元素，并输出。
        return $this->contextual[end($this->buildStack)][$abstract] ?? null;
    }

    public function get($id)
    {
        // TODO: Implement get() method.
    }

    public function has($id)
    {
        // TODO: Implement has() method.
    }

    public function offsetGet($offset)
    {
        // TODO: Implement offsetGet() method.
    }

    public function offsetUnset($offset)
    {
        // TODO: Implement offsetUnset() method.
    }

    public function offsetExists($offset)
    {
        // TODO: Implement offsetExists() method.
    }

    public function offsetSet($offset, $value)
    {
        // TODO: Implement offsetSet() method.
    }
}