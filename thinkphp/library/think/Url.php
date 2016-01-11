<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

class Url
{
    /**
     * URL生成 支持路由反射
     * @param string $url URL表达式，
     * 格式：'[模块/控制器/操作]?参数1=值1&参数2=值2...'
     * @控制器/操作?参数1=值1&参数2=值2...
     * \\命名空间类\\方法?参数1=值1&参数2=值2...
     * @param string|array $vars 传入的参数，支持数组和字符串
     * @param string $suffix 伪静态后缀，默认为true表示获取配置值
     * @param boolean|string $domain 是否显示域名 或者直接传入域名
     * @return string
     */
    public static function build($url = '', $vars = '', $suffix = true, $domain = true)
    {
        // 解析参数
        if (is_string($vars)) {
            // aaa=1&bbb=2 转换成数组
            parse_str($vars, $vars);
        }

        if (strpos($url, '?')) {
            list($url, $params) = explode('?', $url);
            parse_str($params, $params);
            $vars = array_merge($params, $vars);
        }

        // 检测路由
        if ($match = self::getRouteUrl($url, $vars)) {
            // 处理路由规则中的特殊字符
            $url = str_replace('[--think--]', '', $match);
        } else {
            // 路由不存在 直接解析
            $url = self::parseUrl($url);
        }

        // 检测URL绑定
        $type = Route::bind('type');
        if ($type) {
            $bind = Route::bind($type);
            if (0 === strpos($url, $bind)) {
                $url = substr($url, strlen($bind) + 1);
            }
        }
        // 还原URL分隔符
        $depr = Config::get('pathinfo_depr');
        $url  = str_replace('/', $depr, $url);

        // URL后缀
        $suffix = self::parseSuffix($suffix);
        // 参数组装
        if (!empty($params)) {
            // 添加参数
            if (Config::get('url_common_param')) {
                $vars = urldecode(http_build_query($vars));
                $url .= $suffix . '?' . $vars;
            } else {
                foreach ($params as $var => $val) {
                    if ('' !== trim($val)) {
                        $url .= $depr . $var . $depr . urlencode($val);
                    }
                }
                $url .= $suffix;
            }
        } else {
            $url .= $suffix;
        }

        // 检测域名
        $domain = self::parseDomain($url, $domain);

        // URL组装
        $url = $domain . Config::get('base_url') . '/' . $url;
        return $url;
    }

    // 直接解析URL地址
    protected static function parseUrl($url)
    {
        if (false !== strpos($url, '\\')) {
            // 解析到类
            $url = ltrim(str_replace('\\', '/', $url), '/');
        } elseif (0 === strpos($url, '@')) {
            // 解析到控制器
            $url = substr($url, 1);
        } else {
            // 解析到 模块/控制器/操作
            $module = MODULE_NAME ? MODULE_NAME . '/' : '';
            if ('' == $url) {
                // 空字符串输出当前的 模块/控制器/操作
                $url = $module . CONTROLLER_NAME . '/' . ACTION_NAME;
            } else {
                $path = explode('/', $url);
                $len  = count($path);
                if ($len < 3) {
                    $url = $module . (1 == $len ? CONTROLLER_NAME . '/' : '') . $url;
                }
            }
        }
        return $url;
    }

    // 检测域名
    protected static function parseDomain($url, $domain)
    {
        if ($domain) {
            if (true === $domain) {
                // 自动判断域名
                $domain = $_SERVER['HTTP_HOST'];
                if (Config::get('url_domain_deploy')) {
                    // 开启子域名部署
                    $domain = $_SERVER['HTTP_HOST'];
                    foreach (Route::domain() as $key => $rule) {
                        $rule = is_array($rule) ? $rule[0] : $rule;
                        if (false === strpos($key, '*') && 0 === strpos($url, $rule)) {
                            $domain = $key . strstr($domain, '.'); // 生成对应子域名
                            break;
                        }
                    }
                }
            }
            $domain = (self::isSsl() ? 'https://' : 'http://') . $domain;
        } else {
            $domain = '';
        }
        return $domain;
    }

    // 检测变量规则
    protected static function pattern($rule, $vars, $pattern)
    {
        // 检测路由规则中的变量
        // 检测是否设置了参数分隔符
        if ($depr = Config::get('url_params_depr')) {
            $rule = str_replace($depr, '/', $rule);
        }
        // 提取路由规则中的变量
        $var   = [];
        $array = explode('/', $rule);
        foreach ($array as $val) {
            $optional = false;
            if (0 === strpos($val, '[:')) {
                // 可选参数
                $val      = substr($val, 1, -1);
                $optional = true;
            }
            if (0 === strpos($val, ':')) {
                // URL变量
                $name = substr($val, 1);
                if (!$optional && !isset($vars[$name])) {
                    // 变量未设置
                    return false;
                }
            }
        }
        return true;
    }

    // 解析URL后缀
    protected static function parseSuffix($suffix)
    {
        if ($suffix) {
            $suffix = true === $suffix ? Config::get('url_html_suffix') : $suffix;
            if ($pos = strpos($suffix, '|')) {
                $suffix = substr($suffix, 0, $pos);
            }
        }
        return (empty($suffix) || 0 === strpos($suffix, '.')) ? $suffix : '.' . $suffix;
    }

    /**
     * 判断是否SSL协议
     * @return boolean
     */
    public static function isSsl()
    {
        if (isset($_SERVER['HTTPS']) && ('1' == $_SERVER['HTTPS'] || 'on' == strtolower($_SERVER['HTTPS']))) {
            return true;
        } elseif (isset($_SERVER['SERVER_PORT']) && ('443' == $_SERVER['SERVER_PORT'])) {
            return true;
        }
        return false;
    }

    // 匹配路由地址
    public static function getRouteUrl($name, &$vars = [])
    {
        $alias = self::getRouteAlias();
        if (!empty($alias[$name])) {
            foreach ($alias[$name] as $key => $url) {
                // 检查变量匹配
                if (strpos($url, '$')) {
                    $url = str_replace('$', '[--think--]', $url);
                }
                if (self::pattern($url, $vars, $check)) {
                    foreach ($vars as $key => $val) {
                        if (false !== strpos($url, '[:' . $key . ']')) {
                            $url = str_replace('[:' . $key . ']', $val, $url);
                            unset($vars[$key]);
                        } elseif (false !== strpos($url, ':' . $key)) {
                            $url = str_replace(':' . $key, $val, $url);
                            unset($vars[$key]);
                        }
                    }
                    return $url;
                }
            }
        }
        return false;
    }

    // 自动生成路由别名
    private static function getRouteAlias()
    {
        if ($alias = Cache::get('think_route_alias')) {
            return $alias;
        }
        // 获取路由定义
        $rules = Route::getRules();
        if ($rules) {
            foreach ($rules as $rule => $val) {
                if (!empty($val['routes'])) {
                    foreach ($val['routes'] as $key => $route) {
                        if (is_numeric($key)) {
                            $key = array_shift($route);
                        }
                        $route = $route[0];
                        if (strpos($route, '?')) {
                            $route = strstr($route, '?', true);
                        }
                        $alias[$route][] = $rule . '/' . $key;
                    }
                } else {
                    $route = $val['route'];
                    if (strpos($route, '?')) {
                        $route = strstr($route, '?', true);
                    }
                    $alias[$route][] = $rule;
                }
            }
            Cache::set('think_route_alias', $alias);
            return $alias;
        }
        return [];
    }
}
