<?php
/**
 * This class provides a simple interface for OpenID (1.1 and 2.0) authentication.
 * Supports Yadis discovery.
 * The autentication process is stateless/dumb.
 *
 * Usage:
 * Sign-on with OpenID is a two step process:
 * Step one is authentication with the provider:
 * <code>
 * $openid = new LightOpenID;
 * $openid->identity = 'ID supplied by user';
 * header('Location: ' . $openid->authUrl());
 * </code>
 * The provider then sends various parameters via GET, one of them is openid_mode.
 * Step two is verification:
 * <code>
 * if($_GET['openid_mode']) {
 *     $openid = new LightOpenID;
 *     echo $openid->validate() ? 'Logged in.' : 'Failed';
 * }
 * </code>
 *
 * Optionally, you can set $returnUrl and $realm (or $trustRoot, which is an alias).
 * The default values for those are:
 * $openid->realm     = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
 * $openid->returnUrl = $openid->realm . $_SERVER['REQUEST_URI'];
 *
 * If you don't know their meaning, refer to any openid tutorial, or specification. Or just guess.
 *
 * Depends only on curl.
 * Requires PHP 5.
 * @author Mewp
 * @copyright Copyright (c) 2010, Mewp
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class LightOpenID
{
    public $returnUrl
         , $required = array()
         , $optional = array();
    private $identity;
    protected $server, $version, $trustRoot;

    function __construct()
    {
        $this->trustRoot = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $this->returnUrl = $this->trustRoot . $_SERVER['REQUEST_URI'];

        if (!function_exists('curl_exec')) {
            throw new ErrorException('Curl extension is required.');
        }
    }

    function __set($name, $value)
    {
        switch($name) {
        case 'identity':
            if(stripos($value, 'http') === false && $value) $value = 'http://' . $value;
            $this->$name = $value;
            break;
        case 'trustRoot':
        case 'realm':
            $this->trustRoot = $value;
        }
    }

    function __get($name)
    {
        switch($name) {
        case 'identity':
            return $this->$name;
        case 'trustRoot':
        case 'realm':
            return $this->trustRoot;
        }
    }

    protected function request($url, $method='GET', $params=array())
    {
        $params = http_build_query($params);
        $curl = curl_init($url . ($method == 'GET' && $params ? '?' . $params : ''));
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        if($method == 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        } elseif($method == 'HEAD') {
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_NOBODY, true);
        } else {
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        }
        $response = curl_exec($curl);

        if(curl_errno($curl)) {
            throw new ErrorException(curl_error($curl), curl_errno($curl));
        }

        return $response;
    }

    protected function build_url($url, $parts)
    {
        if(isset($url['query'], $parts['query'])) {
            $parts['query'] = $url['query'] . '&' . $parts['query'];
        }

        $url = $parts + $url;
        $url = $url['scheme'] . '://'
             . (empty($url['username'])?''
                 :(empty($url['password'])? "{$url['username']}@"
                 :"{$url['username']}:{$url['password']}@"))
             . $url['host']
             . (empty($url['port'])?'':":{$url['port']}")
             . (empty($url['path'])?'':$url['path'])
             . (empty($url['query'])?'':"?{$url['query']}")
             . (empty($url['fragment'])?'':":{$url['fragment']}");
        return $url;
    }

    protected function htmlTag($content, $tag, $attrName, $attrValue, $valueName)
    {
        preg_match_all("#<{$tag}[^>]*$attrName=['\"].*?$attrValue.*?['\"][^>]*$valueName=['\"](.+?)['\"][^>]*/?>#i", $content, $matches1);
        preg_match_all("#<{$tag}[^>]*$attrName=['\"].*?$attrValue.*?['\"][^>]*$valueName=['\"](.+?)['\"][^>]*/?>#i", $content, $matches1);
        preg_match_all("#<{$tag}[^>]*$valueName=['\"](.+?)['\"][^>]*$attrName=['\"].*?$attrValue.*?['\"][^>]*/?>#i", $content, $matches2);

        $result = array_merge($matches1[1], $matches2[1]);
        return empty($result)?false:$result[0];
    }

    /**
     * Performs Yadis and HTML discovery. Normally not used.
     * @param $url Identity URL.
     * @return String OP Endpoint (i.e. OpenID provider address).
     * @throws ErrorException
     */
    function discover($url)
    {
        if(!$url) throw new ErrorException('No identity supplied.');
        # We save the original url in case of Yadis discovery failure.
        # It can happen when we'll be lead to an XRDS document
        # which does not have any OpenID2 services.
        $originalUrl = $url;

        # A flag to disable yadis discovery in case of failure in headers.
        $yadis = true;

        # We'll jump a maximum of 5 times, to avoid endless redirections.
        for($i = 0; $i < 5; $i ++) {
            if($yadis) {
                $headers = explode("\n",$this->request($url, 'HEAD'));

                $next = false;
                foreach($headers as $header) {
                    if(preg_match('#X-XRDS-Location\s*:\s*(.*)#', $header, $m)) {
                        $url = $this->build_url(parse_url($url), parse_url(trim($m[1])));
                        $next = true;
                    }

                    if(preg_match('#Content-Type\s*:\s*application/xrds\+xml#i', $header)) {
                        # Found an XRDS document, now let's find the server, and optionally delegate.
                        $content = $this->request($url, 'GET');

                        # OpenID 2
                        $ns = preg_quote('http://specs.openid.net/auth/2.0');
                        if(preg_match('#<Service.*?>(.*)<Type>\s*'.$ns.'.*?</Type>(.*)</Service>#s', $content, $m)) {
                            $content = $m[1] . $m[2];

                            $content = preg_match('#<URI>(.*)</URI>#', $content, $server);
                            $content = preg_match('#<LocalID>(.*)</LocalID>#', $content, $delegate);
                            if(empty($server)) {
                                return false;
                            }

                            $server = $server[1];
                            if(isset($delegate[1])) $this->identity = $delegate[1];
                            $this->version = 2;

                            $this->server = $server;
                            return $server;
                        }

                        # OpenID 1.1
                        $ns = preg_quote('http://openid.net/signon/1.1');
                        if(preg_match('#<Service.*?>(.*)<Type>\s*'.$ns.'\s*</Type>(.*)</Service>#s', $content, $m)) {
                            $content = $m[1] . $m[2];

                            $content = preg_match('#<URI>(.*)</URI>#', $content, $server);
                            $content = preg_match('#<.*?Delegate>(.*)</.*?Delegate>#', $content, $delegate);
                            if(empty($server)) {
                                return false;
                            }

                            $server = $server[1];
                            if(isset($delegate[1])) $this->identity = $delegate[1];
                            $this->version = 1;

                            $this->server = $server;
                            return $server;
                        }

                        $next = true;
                        $yadis = false;
                        $url = $originalUrl;
                        $content = null;
                        break;
                    }
                }
                if($next) continue;

                # There are no relevant information in headers, so we search the body.
                $content = $this->request($url, 'GET');
                if($location = $this->htmlTag($content, 'meta', 'http-equiv', 'X-XRDS-Location', 'value')) {
                    $url = $this->build_url(parse_url($url), parse_url($location));
                    continue;
                }
            }

            if(!$content) $content = $this->request($url, 'GET');

            # At this point, the YADIS Discovery has failed, so we'll switch
            # to openid2 HTML discovery, then fallback to openid 1.1 discovery.
            $server   = $this->htmlTag($content, 'link', 'rel', 'openid2.provider', 'href');
            $delegate = $this->htmlTag($content, 'link', 'rel', 'openid2.local_id', 'href');
            $this->version = 2;

            if(!$server) {
                # The same with openid 1.1
                $server   = $this->htmlTag($content, 'link', 'rel', 'openid.server', 'href');
                $delegate = $this->htmlTag($content, 'link', 'rel', 'openid.delegate', 'href');
                $this->version = 1;
            }

            if($server) {
                # We found an OpenID2 OP Endpoint
                if($delegate) {
                    # We have also found an OP-Local ID.
                    $this->identity = $delegate;
                }
                $this->server = $server;
                return $server;
            }

            throw new ErrorException('No servers found!');
        }
        throw new ErrorException('Endless redirection!');
    }

    protected function authUrl_v1()
    {
        $params = array(
            'openid.return_to'  => $this->returnUrl,
            'openid.mode'       => 'checkid_setup',
            'openid.identity'   => $this->identity,
            'openid.trust_root' => $this->trustRoot,
            );

        if(count($this->required)) {
            $params['openid.sreg.required'] = implode(',',$this->required);
        }

        if(count($this->optional)) {
            $params['openid.sreg.optional'] = implode(',',$this->optional);
        }

        return $this->build_url(parse_url($this->server)
                               , array('query' => http_build_query($params)));
    }

    protected function authUrl_v2($identifier_select)
    {
        $params = array(
            'openid.ns'          => 'http://specs.openid.net/auth/2.0',
            'openid.mode'        => 'checkid_setup',
            'openid.return_to'   => $this->returnUrl,
            'openid.realm'       => $this->trustRoot,
        );
        
        if($identifier_select) {
            $params['openid.identity'] = $params['openid.claimed_id']
                 = 'http://specs.openid.net/auth/2.0/identifier_select';
        } else {
            $params['openid.identity'] = $params['openid.claimed_id'] = $this->identity;
        }

        return $this->build_url(parse_url($this->server)
                               , array('query' => http_build_query($params)));
    }

    /**
     * Returns authentication url. Usually, you want to redirect your user to it.
     * @return String The authentication url.
     * @param String $select_identifier Whether to request OP to select identity for an user in OpenID 2. Does not affect OpenID 1.
     * @throws ErrorException
     */
    function authUrl($identifier_select = false)
    {
        if(!$this->server) $this->Discover($this->identity);

        if($this->version == 2) {
            return $this->authUrl_v2($identifier_select);
        }
        return $this->authUrl_v1();
    }

    /**
     * Performs OpenID verification with the OP.
     * @return Bool Whether the verification was successful.
     * @throws ErrorException
     */
    function validate()
    {
        $params = array(
            'openid.assoc_handle' => $_GET['openid_assoc_handle'],
            'openid.signed'       => $_GET['openid_signed'],
            'openid.sig'          => $_GET['openid_sig'],
            );

        if(isset($_GET['openid_op_endpoint'])) {
            # We're dealing with an OpenID 2.0 server, so let's set an ns
            # Even though we should know location of the endpoint,
            # we still need to verify it by discovery, so $server is not set here
            $params['openid.ns'] = 'http://specs.openid.net/auth/2.0';
        }
        $server = $this->discover($_GET['openid_identity']);

        foreach(explode(',', $_GET['openid_signed']) as $item) {
            $params['openid.' . $item] = $_GET['openid_' . str_replace('.','_',$item)];
        }

        $params['openid.mode'] = 'check_authentication';

        $response = $this->request($server, 'POST', $params);

        return preg_match('/is_valid\s*:\s*true/i', $response);
    }
}
