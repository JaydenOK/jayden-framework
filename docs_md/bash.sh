#请求10次某个接口
function requestApiTimes() {
  url="https://xxx.com";runTimes=20; for ((i=1; i<=$runTimes; i++)); do echo "Request #$i"; curl $url; echo;done
}

