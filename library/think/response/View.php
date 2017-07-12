<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2017 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\response;

use think\Config;
use think\Response;
use think\View as ViewTemplate;

class View
{
    // 输出参数
    public    $options     = [];
    protected $vars        = [];
    protected $replace     = [];
    public    $contentType = 'text/html';

    /** @var string 模版文件名，默认使用控制器/方法同名模版 */
    protected $template = '';

    /**
     * 处理数据
     * @access protected
     * @param mixed $data 要处理的数据
     * @return mixed
     */
    public function output ($data)
    {
        $this->assign($data);

        // 渲染模板输出
        return ViewTemplate::instance(Config::get('template'), Config::get('view_replace_str'))
            ->fetch($this->template, $this->vars, $this->replace);
    }

    /**
     * 设置模版名称
     * @param string $template
     */
    public function template ($template)
    {
        $this->template = $template;

        return Response::instance();
    }

    /**
     * 获取视图变量
     * @access public
     * @param string $name 模板变量
     * @return mixed
     */
    public function getVars ($name = NULL)
    {
        if (is_null($name)) {
            return $this->vars;
        } else {
            return isset($this->vars[$name]) ? $this->vars[$name] : NULL;
        }
    }

    /**
     * 模板变量赋值
     * @access public
     * @param mixed $name  变量名
     * @param mixed $value 变量值
     * @return Response
     */
    public function assign ($name, $value = '')
    {
        if (is_array($name)) {
            $this->vars = array_merge($this->vars, $name);

            return Response::instance();
        } else {
            $this->vars[$name] = $value;
        }

        return Response::instance();
    }

    /**
     * 视图内容替换
     * @access public
     * @param string|array $content 被替换内容（支持批量替换）
     * @param string       $replace 替换内容
     * @return Response
     */
    public function replace ($content, $replace = '')
    {
        if (is_array($content)) {
            $this->replace = array_merge($this->replace, $content);
        } else {
            $this->replace[$content] = $replace;
        }

        return Response::instance();
    }

}
