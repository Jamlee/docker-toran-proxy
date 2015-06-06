# docker-toran-proxy

caching composer package by yourself .this project inspired by `docker image: cedvan/toran-proxy`

---

##0x1:Check it out

![install](https://raw.githubusercontent.com/Jamlee/docker-toran-proxy/master/asset/install.gif)

##0x2:How to configure with docker

**change ip and port **

>  modify file: ./service/toran/app/config/parameters.yml
>  modify file: ./service/toran-proxy-http.conf #changing port

note:

>  default configuration is 192.168.1.12:8002,so you just need to replace some it to yours.


##0x3:Starting

`docker-compser up` and open the url 192.168.1.12:8002

##troubleshooting
...





