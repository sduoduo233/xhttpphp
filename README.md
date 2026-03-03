# XHTTP VLESS

PHP 实现的 VLESS XHTTP，支持虚拟主机

## 部署方法

1. 上传并解压 `release.zip`
2. 保活 `worker.php`。有两种方法：
   - 写一个程序循环 curl `worker.php`
   - 添加一个定时任务，每分钟访问一次 `worker.php`。
3. Xray 配置：  
仅支持 `packet-up` 模式。把 `/xhttp.php` 改成你的 `xhttp.php` 的路径。

```
{
    "inbounds": [
        {
            "port": 6000,
            "protocol": "socks",
            "settings": {
                "udp": true
            }
        }
    ],
    "outbounds": [
        {
            "protocol": "vless",
            "settings": {
                "address": "example.com",
                "port": 443,
                "encryption": "none",
                "id": "..."
            },
            "streamSettings": {
                "network": "xhttp",
                "xhttpSettings": {
                    "path": "/xhttp.php",
                    "mode": "packet-up"
                },
                "security": "tls"
            }
        }
    ]
}
```


## 已知问题

- 不检查 VLESS UUID。建议把程序放到一个复杂的目录名下。
- UDP 只支持 53 端口的 DNS。
- netcup webhosting 好像有一些问题，~~懒得修了~~

## 开发

1. 运行 `docker compose up`
2. Vscode 配置

```
{
    "version": "0.2.0",
    "configurations": [

        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003
        }
    ]
}
```