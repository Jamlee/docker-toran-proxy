# docker-toran-proxy

caching composer package by yourself .this project inspired by `docker image: cedvan/toran-proxy`

---

##0x1:Check it out

![install](https://raw.githubusercontent.com/Jamlee/docker-toran-proxy/master/asset/install.gif)

##0x2:How to configure with docker

**change ip and port**

>  modify file: ./service/toran/app/config/parameters.yml #changing ip

>  modify file: ./service/toran-proxy-http.conf #changing port

note:

>  1. default configuration is 192.168.1.12:8002,so you just need to replace  it with yours.
>  2. you also can modify the configuration by initial shell script . the path to script is `./service/toran-proxy`

**config git setting**

![config](https://raw.githubusercontent.com/Jamlee/docker-toran-proxy/master/asset/config.png)

> you could see what i marked above,the path `/data/toran-proxy/mirrors` is inside container,the git clone url `jamlee@192.168.1.12:/home/jamlee/JAM/lab/toran/data/mirrors/` is on host.So every computer that can reach the host computer can use this private repository.


##0x3:Starting

`docker-compser up` and open the url 192.168.1.12:8002

##troubleshooting






