<?php
/**
 * Fluent.php
 * Created by PhpStorm.
 * User: YangQi
 * Date: 2016/10/18
 * Time: 16:50
 */

namespace think\log\driver;


class Fluent {
	protected $handler = NULL;

	protected $config = [
		// Fluent 服务器地址及协议
		'host'       => 'tcp://127.0.0.1',
		//端口号
		'port'       => 24224,
		//超时时间
		'timeout'    => 3,
		//是否长链接
		'persistent' => true,
		//是否异步模式
		'async'      => false,
		//写入失败后是否重试
		'retry'      => true,
		//重试次数
		'retryMax'   => 2,
		//重试时的等待时间，单位：微秒，1000 = 0.001秒
		'retryWait'  => 1000,
		//标签前缀，系统会把没有.分隔的标签统一加上此前缀
		'prefix'     => '',
		//写入失败后是否保存到文件
		'saveFile'   => true,
		//保存到文件的路径
		'path'       => LOG_PATH . 'fluent' . DS,
		//单次写入数据长度
		'limit'      => 10240,
	];

	protected $destination;

	protected $uniqid;

	/**
	 * 架构函数
	 * @param array $config 缓存参数
	 * @access public
	 */
	public function __construct (array $config = []) {
		if (!empty($config)) {
			$this->config = array_merge($this->config, $config);
		}

		//连接Fluent服务器
		$this->connect();
	}

	/**
	 * 连接Fluent服务器
	 */
	protected function connect () {
		if (!is_null($this->handler)) {
			return $this->handler;
		}

		$flags = STREAM_CLIENT_CONNECT;

		if ($this->config['persistent']) {
			$flags |= STREAM_CLIENT_PERSISTENT;
		}

		if ($this->config['async']) {
			$flags |= STREAM_CLIENT_ASYNC_CONNECT;
		}

		try {
			$this->handler = stream_socket_client($this->config['host'] . ':' . $this->config['port'], $errno, $errstr, $this->config['timeout'], $flags);

			// 设置 socket 的读写超时时间
			stream_set_timeout($this->handler, $this->config['timeout']);
		} catch (\Exception $e) {
			$this->handler = NULL;
			is_dir($this->config['path']) || mkdir($this->config['path'], 0755, true);
			//保存一个 fluent 错误日志
			error_log('[' . $_SERVER['REQUEST_TIME_FLOAT'] . '] ' . $e->getMessage() . "\r\n", 3, $this->config['path'] . 'error.log');
		}

		return $this->handler;
	}

	/**
	 * 关闭连接
	 */
	protected function close () {
		if (is_resource($this->handler)) {
			fclose($this->handler);
			$this->handler = NULL;
		}
	}

	/**
	 * 写入数据到 fluent
	 * @param string $tag 标签名称
	 * @param array  $data
	 * @return bool
	 */
	protected function post ($tag, $data) {
		$packed   = $this->pack($tag, $data);
		$len      = strlen($packed);
		$writeLen = 0; //已写入长度
		$retryNum = 0; //重试次数
		try {
			// 连接资源为空，抛出错误直接写到文件
			if (is_null($this->handler)) {
				throw new \Exception("连接资源异常");
			}
			while ($writeLen < $len) {
				$ret = @fwrite($this->handler, $packed, $this->config['limit']);
				// 写入失败
				if ($ret === false || $ret === '') {
					throw new \Exception("写入失败");
				} elseif ($ret === 0) {
					// 未开启写入失败后重试，或超过重试次数
					if (!$this->config['retry'] || $retryNum >= $this->config['retryMax']) {
						throw new \Exception("未开启写入失败后重试，或超过重试次数");
					}

					// 还有最后一次重试机会前，先断掉重连
					if ($retryNum === $this->config['retryMax'] - 1) {
						$this->close();
						$this->connect();
					}

					usleep($this->config['retryWait']);
					$retryNum++;
					continue;
				}

				//增加已写入长度
				$writeLen += $ret;
				//新的字符串
				$packed = substr($packed, $ret);
			}
		} catch (\Exception $e) {
			//写入失败，且开启了保存到文件功能
			if ($this->config['saveFile']) {
				error_log($this->pack($tag, $data, 0, "\r\n") . "\r\n", 3, $this->destination);
			}

			return false;
		}

		return true;
	}

	/**
	 * 数据打包
	 * @param  string $tag  标签名称
	 * @param  array  $data 数据
	 * @param int     $time 时间
	 * @param string  $glue 多条数据的分隔符
	 * @return string
	 */
	protected function pack ($tag, $data, $time = 0, $glue = '') {
		$time = $time ?: time();
		if (!isset($data[0])) {
			$data['uniqid'] = $this->uniqid;

			return json_encode([$tag, $time, $data]);
		} else {
			$packed = [];
			foreach ($data AS $idx => $item) {
				$item['uniqid'] = $this->uniqid;
                $item['_idx_'] = $idx + 1;
				$packed[]       = json_encode([$tag, $time, $item]);
			}

			return implode($glue, $packed);
		}
	}

	/**
	 * 用户发送的请求头信息
	 * @return array
	 */
	protected function getRequestHeaders () {
		$ret = [];
		foreach ($_SERVER as $K => $V) {
			$a = explode('_', $K);
			if (array_shift($a) == 'HTTP') {
				array_walk($a, function (&$v) {
					$v = ucfirst(strtolower($v));
				});
				$ret[join('-', $a)] = $V;
			}
		}

		return $ret;
	}

	/**
	 * 服务器返回的头信息
	 * @return array
	 */
	protected function getResponseHeaders () {
		$ret  = [];
		$list = headers_list();
		foreach ($list as $v) {
			list($k, $v) = explode(':', $v, 2);
			$v       = ltrim($v);
			$ret[$k] = $v;
		}

		return $ret;
	}

	/**
	 * 日志写入接口
	 * @access public
	 * @param array $log 日志信息
	 * @return bool
	 */
	public function save (array $log = []) {
		//开启了失败后写入文件功能，先初始化目录
		if ($this->config['saveFile']) {
			$this->initDestination();
		}

		// 连接资源为空，再次尝试连接
		if (is_null($this->handler)) {
			$this->connect();
		}

        $request      = \think\Request::instance();
        $response     = \think\Response::instance();
        $url = (IS_CLI ? 'CLI ' : '') . $request->url(IS_CLI ? null : true );

		$gather = $log['gather'] ?? [];
		unset($log['gather']);

		if ($log) {
            //将TP核心日志转为数据集
            $gather['debug'] = [
                'url' => $url,
            ];
            foreach ($log AS $type => $item) {
                $gather['debug'][$type] = $item;
            }
        }

		$httpCode     = $response->getCode();
		$this->uniqid = md5(uniqid('', true) . mt_rand());

		$gather['base'] = array_merge([
			'url'       => $url,
			'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
			'code'      => $httpCode,
			'module'    => $request->module(),
			'runtime'   => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
			'file_load' => count(get_included_files()),
			//服务器信息，方便分布式布署时查询
			'server'    => [
				'software' => $_SERVER["SERVER_SOFTWARE"] ?? '',
				'addr'     => $_SERVER['SERVER_ADDR'] ?? '',
			],
			'request'   => [
				'val'         => $_REQUEST,
				'header'      => $this->getRequestHeaders(),
				'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? '',
			],
			'response'  => [
				'header' => $this->getResponseHeaders(),
				'body'   => isset($gather['base']['error']) ? '' : ($response->getType() == 'json' ? json_decode($response->getContent(), true) : $response->getContent()),
			],
		], $gather['base'] ?? []);

		foreach ($gather AS $tag => $item) {
			$this->post(strpos($tag, '.') ? $tag : $this->config['prefix'] . $tag, $item);
		}
	}

	/**
	 * 初始化日志目录，失败后禁用写入文件功能
	 */
	protected function initDestination () {
		$destination = $this->config['path'] . date('Ym') . DS . date('d') . '.log';
		$path        = dirname($destination);
		try {
			!is_dir($path) && mkdir($path, 0755, true);
			$this->destination = $destination;
		} catch (\Exception $e) {
			$this->config['saveFile'] = false;
		}
	}
}