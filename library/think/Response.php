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

namespace think;

use think\Cache;
use think\Config;
use think\Debug;
use think\Env;
use think\response\Redirect;
use think\response\View;

/**
 * @package think
 * @method Redirect with(string | array $name, mixed $value = NULL) 重定向传值（通过Session）
 * @method string getTargetUrl() 获取跳转地址
 * @method Redirect params(array $params = []) 跳转地址为 <b>相对</b> 地址时，设置跳转的参数
 * @method remember() 记住当前url后跳转
 * @method restore() 跳转到上次记住的url
 * @method View template(string $template) 指定模版名称
 * @method getVars(string $name = NULL) 获取视图变量
 * @method View assign(string | array $name, $value = '') 获取视图变量
 * @method View replace(string | array $content, $replace = '') 视图内容替换
 **/
class Response
{
    /**
     * @var \think\Response 对象实例
     */
    protected static $instance;

    /**
     * @var string 输出类型，为空是自动获取，可指定：json|jsonp|xml
     */
    protected $type = NULL;

    /** @var  object 扩展方法的操作句柄 */
    protected $handler;

    // 原始数据
    protected $data;

    // 当前的contentType
    protected $contentType = 'text/html';

    // 字符集
    protected $charset = 'utf-8';

    //状态
    protected $code = 200;

    // 输出参数
    protected $options = [];
    // header参数
    protected $header = [];

    protected $content = NULL;

    /**
     * 构造函数
     * @access   public
     * @param mixed $data    输出数据
     * @param int   $code
     * @param array $header
     * @param array $options 输出参数
     */
    public function __construct ($data = '', $code = 200, array $header = [], $options = [])
    {
        $this->data($data);
        $this->header = $header;
        $this->code   = $code;
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
    }

    /**
     * 初始化
     * @access public
     * @param array $options 参数
     * @return \think\Response
     */
    public static function instance ()
    {
        if (is_null(self::$instance)) {
            self::$instance = new static();
        }

        return self::$instance;
    }


    /**
     * 创建Response对象
     * @access public
     * @param mixed  $data    输出数据
     * @param string $type    输出类型
     * @param int    $code
     * @param array  $header
     * @param array  $options 输出参数
     * @return \think\Response
     */
    public static function create ($data = '', $type = '', $code = 0, array $header = [], $options = [])
    {
        $instance = static::instance();

        $instance->data($data)
            ->type($type ?: $instance->getType())
            ->code($code ?: $instance->getCode())
            ->header($header)
            ->options($options);

        return $instance;
    }

    /**
     * 发送数据到客户端
     * @access public
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function send ()
    {
        // 处理输出数据
        $data = $this->getContent();

        // Trace调试注入
        if (Env::get('app_trace', Config::get('app_trace'))) {
            Debug::inject($this, $data);
        }

        if (200 == $this->code) {
            $cache = Request::instance()->getCache();
            if ($cache) {
                $this->header['Cache-Control'] = 'max-age=' . $cache[1] . ',must-revalidate';
                $this->header['Last-Modified'] = gmdate('D, d M Y H:i:s') . ' GMT';
                $this->header['Expires']       = gmdate('D, d M Y H:i:s', $_SERVER['REQUEST_TIME'] + $cache[1]) . ' GMT';
                Cache::set($cache[0], [$data, $this->header], $cache[1]);
            }
        }

        if (!headers_sent() && !empty($this->header)) {
            // 发送状态码
            http_response_code($this->code);
            // 发送头部信息
            foreach ($this->header as $name => $val) {
                header($name . ':' . $val);
            }
        }

        echo $data;

        if (function_exists('fastcgi_finish_request')) {
            // 提高页面响应
            fastcgi_finish_request();
        }

        // 监听response_end
        Hook::listen('response_end', $this);

        // 清空当次请求有效的数据
        Session::flush();
    }

    /**
     * 根据页面处理类型调用扩展方法
     * @param $method
     * @param $arguments
     * @return mixed
     * @throws Exception
     */
    public function __call ($method, $arguments)
    {
        if (method_exists($this->handler, $method)) {
            return call_user_func_array([$this->handler, $method], $arguments);
        } else {
            throw new Exception('method not exists:' . __CLASS__ . '->' . $method);
        }
    }

    /**
     * 处理数据
     * @access protected
     * @param mixed $data 要处理的数据
     * @return mixed
     */
    protected function output ($data)
    {
        if ($this->handler !== false && method_exists($this->handler, 'output')) {
            //将先选项传递过去
            $this->handler->options = array_merge($this->handler->options, $this->options);
            $data                   = call_user_func_array([$this->handler, 'output'], [$data]);
        }
        //没有设置有效的数据处理方式，且数据为数组时，转成字符串
        elseif (is_array($data)) {
            $data = var_export($data, true);
        }

        return $data;
    }

    /**
     * 输出的参数
     * @access public
     * @param mixed $options 输出参数
     * @return $this
     */
    public function options ($options = [])
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * 输出数据设置
     * @access public
     * @param mixed $data 输出数据
     * @return $this
     */
    public function data ($data)
    {
        $this->data = $data;

        return $this;
    }

    /**
     * 设置响应头
     * @access public
     * @param string|array $name  参数名
     * @param string       $value 参数值
     * @return $this
     */
    public function header ($name, $value = NULL)
    {
        if (is_array($name)) {
            $this->header = array_merge($this->header, $name);
        } else {
            $this->header[$name] = $value;
        }

        return $this;
    }

    /**
     * 设置页面输出内容
     * @param $content
     * @return $this
     */
    public function content ($content)
    {
        if (NULL !== $content && !is_string($content) && !is_numeric($content) && !is_callable([
                $content,
                '__toString',
            ])
        ) {
            throw new \InvalidArgumentException(sprintf('variable type error： %s', gettype($content)));
        }

        $this->content = (string)$content;

        return $this;
    }

    /**
     * 获取页面返回类型
     * @param string $type
     * @return string
     */
    public function getType ()
    {
        if (is_null($this->type)) {
            return Request::instance()->isAjax() ? Config::get('default_ajax_return') : Config::get('default_return_type');
        } else {
            return $this->type;
        }
    }

    /**
     * 设置页面返回类型
     * @param $type
     * @return $this
     */
    public function type ($type)
    {
        // 返回类型没有改变时，直接返回，避免多次运算
        if ($type == $this->type) {
            return $this;
        }
        $this->type  = $type;
        $contentType = $this->contentType;
        // 载入类型相关的类，便于扩展方法
        $type  = $this->getType();
        $class = false !== strpos($type, '\\') ? $type : '\\think\\response\\' . ucfirst($type);
        if (class_exists($class)) {
            $this->handler = new $class;
            //配置有内容类型
            if (isset($this->handler->contentType)) {
                $contentType = $this->handler->contentType;
            }
        } else {
            $this->handler = false;
        }
        $this->contentType($contentType);

        return $this;
    }

    /**
     * 发送HTTP状态
     * @param integer $code 状态码
     * @return $this
     */
    public function code ($code)
    {
        $this->code = $code;

        return $this;
    }

    /**
     * LastModified
     * @param string $time
     * @return $this
     */
    public function lastModified ($time)
    {
        $this->header['Last-Modified'] = $time;

        return $this;
    }

    /**
     * Expires
     * @param string $time
     * @return $this
     */
    public function expires ($time)
    {
        $this->header['Expires'] = $time;

        return $this;
    }

    /**
     * ETag
     * @param string $eTag
     * @return $this
     */
    public function eTag ($eTag)
    {
        $this->header['ETag'] = $eTag;

        return $this;
    }

    /**
     * 页面缓存控制
     * @param string $cache 状态码
     * @return $this
     */
    public function cacheControl ($cache)
    {
        $this->header['Cache-control'] = $cache;

        return $this;
    }

    /**
     * 页面输出类型
     * @param string $contentType 输出类型
     * @param string $charset     输出编码
     * @return $this
     */
    public function contentType ($contentType, $charset = 'utf-8')
    {
        $this->header['Content-Type'] = $contentType . '; charset=' . $charset;

        return $this;
    }

    /**
     * 获取头部信息
     * @param string $name 头部名称
     * @return mixed
     */
    public function getHeader ($name = '')
    {
        if (!empty($name)) {
            return isset($this->header[$name]) ? $this->header[$name] : NULL;
        } else {
            return $this->header;
        }
    }

    /**
     * 获取原始数据
     * @return mixed
     */
    public function getData ()
    {
        return $this->data;
    }

    /**
     * 获取输出数据
     * @return mixed
     */
    public function getContent ()
    {
        if (NULL == $this->content) {
            $content = $this->output($this->data);

            if (NULL !== $content && !is_string($content) && !is_numeric($content) && !is_callable([
                    $content,
                    '__toString',
                ])
            ) {
                throw new \InvalidArgumentException(sprintf('variable type error： %s', gettype($content)));
            }

            $this->content = (string)$content;
        }

        return $this->content;
    }

    /**
     * 获取状态码
     * @return integer
     */
    public function getCode ()
    {
        return $this->code;
    }
}
