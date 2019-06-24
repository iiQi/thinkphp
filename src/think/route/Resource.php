<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\route;

use think\Route;

/**
 * 资源路由类
 */
class Resource extends RuleGroup
{
    /**
     * 资源路由名称
     * @var string
     */
    protected $resource;

    /**
     * 资源路由地址
     * @var string
     */
    protected $route;

    /**
     * REST方法定义
     * @var array
     */
    protected $rest = [];

    /**
     * 架构函数
     * @access public
     * @param  Route         $router     路由对象
     * @param  RuleGroup     $parent     上级对象
     * @param  string        $name       资源名称
     * @param  string        $route      路由地址
     * @param  array         $rest       资源定义
     */
    public function __construct(Route $router, RuleGroup $parent = null, string $name = '', string $route = '', array $rest = [])
    {
        $name           = ltrim($name, '/');
        $this->router   = $router;
        $this->parent   = $parent;
        $this->resource = $name;
        $this->route    = $route;
        $this->name     = strpos($name, '.') ? strstr($name, '.', true) : $name;

        $this->setFullName();

        // 资源路由默认为完整匹配
        $this->option['complete_match'] = true;

        $this->rest = $rest;

        if ($this->parent) {
            $this->domain = $this->parent->getDomain();
            $this->parent->addRuleItem($this);
        }

        if ($router->isTest()) {
            $this->buildResourceRule();
        }
    }

    /**
     * 生成资源路由规则
     * @access protected
     * @return void
     */
    protected function buildResourceRule(): void
    {
        $rule   = $this->resource;
        $option = $this->option;
        $origin = $this->router->getGroup();
        $this->router->setGroup($this);

        if (strpos($rule, '.')) {
            // 注册嵌套资源路由
            $array = explode('.', $rule);
            $last  = array_pop($array);
            $item  = [];

            foreach ($array as $val) {
                $item[] = $val . '/<' . ($option['var'][$val] ?? $val . '_id') . '>';
            }

            $rule = implode('/', $item) . '/' . $last;
        }

        $prefix = substr($rule, strlen($this->name) + 1);

        // 注册资源路由
        foreach ($this->rest as $key => $val) {
            if ((isset($option['only']) && !in_array($key, $option['only']))
                || (isset($option['except']) && in_array($key, $option['except']))) {
                continue;
            }

            if (isset($last) && strpos($val[1], '<id>') && isset($option['var'][$last])) {
                $val[1] = str_replace('<id>', '<' . $option['var'][$last] . '>', $val[1]);
            } elseif (strpos($val[1], '<id>') && isset($option['var'][$rule])) {
                $val[1] = str_replace('<id>', '<' . $option['var'][$rule] . '>', $val[1]);
            }

            $ruleItem = $this->addRule(trim($prefix . $val[1], '/'), $this->route . '/' . $val[2], $val[0]);

            if (isset($option['resource_model'][$key])) {
                call_user_func_array([$ruleItem, 'model'], (array) $option['resource_model'][$key]);
            }

            if (isset($option['resource_validate'][$key])) {
                call_user_func_array([$ruleItem, 'validate'], (array) $option['resource_validate'][$key]);
            }
        }

        $this->router->setGroup($origin);
    }

    /**
     * 设置资源允许
     * @access public
     * @param  array $only 资源允许
     * @return $this
     */
    public function only(array $only)
    {
        return $this->setOption('only', $only);
    }

    /**
     * 设置资源排除
     * @access public
     * @param  array $except 排除资源
     * @return $this
     */
    public function except(array $except)
    {
        return $this->setOption('except', $except);
    }

    /**
     * 设置资源路由的变量
     * @access public
     * @param  array $vars 资源变量
     * @return $this
     */
    public function vars(array $vars)
    {
        return $this->setOption('var', $vars);
    }

    /**
     * 绑定资源验证
     * @access public
     * @param  array $validate 验证信息
     * @return $this
     */
    public function withValidate(array $validate)
    {
        return $this->setOption('resource_validate', $validate);
    }

    /**
     * 绑定资源模型
     * @access public
     * @param  array $model 模型绑定
     * @return $this
     */
    public function withModel(array $model)
    {
        return $this->setOption('resource_model', $model);
    }

    /**
     * rest方法定义和修改
     * @access public
     * @param  array|string  $name 方法名称
     * @param  array|bool    $resource 资源
     * @return $this
     */
    public function rest($name, $resource = [])
    {
        if (is_array($name)) {
            $this->rest = $resource ? $name : array_merge($this->rest, $name);
        } else {
            $this->rest[$name] = $resource;
        }

        return $this;
    }

}
