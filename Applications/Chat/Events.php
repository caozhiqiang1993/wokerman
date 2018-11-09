<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose 
 */
use \GatewayWorker\Lib\Gateway;

class Events
{
    static public $redis;
    /**
     * 当businessWorker进程启动时触发
     */
    public static function onWorkerStart()
    {
        require_once __DIR__ . '/../../extend/redisTo/RedisTo.php';
        $config = [
            'host' => '127.0.0.1',
            'port' => '6379',
            'auth' => '123456',
        ];
        self::$redis = RedisTo::getInstance($config);
    }

   /**
    * 有消息时
    * @param int $client_id
    * @param mixed $message
    */
   public static function onMessage($client_id, $message)
   {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
        
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }
        
        // 根据类型执行不同的业务
        switch($message_data['type'])
        {
            // 客户端回应服务端的心跳
            case 'pong':
                return;
            // 客户端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':
                Gateway::bindUid($client_id,$message_data['uid']);
                //登录进来查找有没有未读消息
                if(self::$redis->hExists('notSendMsg',$message_data['uid'])){
                    $allMsg = self::$redis->hGet('notSendMsg',$message_data['uid']);
                    $allMsg = json_decode($allMsg,true);
                    foreach($allMsg as $v){
                        Gateway::sendToUid($message_data['uid'],$v);
                    }
                    self::$redis->hDel('notSendMsg',$message_data['uid']);
                }
                return;
                
            // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'msg':
                //判断是否在线，在线直接发送，不在先存起来
                if(Gateway::isUidOnline($message_data['fuid']) == 0){
                    $arr = [];
                    if(self::$redis->hExists('notSendMsg',$message_data['fuid'])){
                        $olbMsg = self::$redis->hGet('notSendMsg',$message_data['fuid']);
                        if($olbMsg){
                            $arr = json_decode($olbMsg,true);
                        }
                    }
                    array_push($arr,$message);
                    self::$redis->hSet('notSendMsg',$message_data['fuid'],json_encode($arr));
                }else{
                    return Gateway::sendToUid($message_data['fuid'],$message);
                }

        }
   }
   
   /**
    * 当客户端断开连接时
    * @param integer $client_id 客户端id
    */
   public static function onClose($client_id)
   {
       // debug
       echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";
   }
  
}
