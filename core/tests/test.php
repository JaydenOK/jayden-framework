<?php


use module\utils\Xml;

require '../bootstrap.php';

$xml = '<xml>
    <SuiteId><![CDATA[ww4asffe99e54c0f4c]]></SuiteId>
    <AuthCorpId><![CDATA[wxf8b4f85f3axxxxxx]]></AuthCorpId>
    <InfoType><![CDATA[change_contact]]></InfoType>
    <TimeStamp>1403610513</TimeStamp>
    <ChangeType><![CDATA[create_user]]></ChangeType>
    <UserID><![CDATA[zhangsan]]></UserID>
    <Name><![CDATA[张三]]></Name>
    <Department><![CDATA[1,2,3]]></Department>
    <IsLeaderInDept><![CDATA[1,0,0]]></IsLeaderInDept>
    <Mobile><![CDATA[11111111111]]></Mobile>
    <Position><![CDATA[产品经理]]></Position>
    <Gender>1</Gender>
    <Email><![CDATA[zhangsan@xxx.com]]></Email>
    <Avatar><![CDATA[http://wx.qlogo.cn/mmopen/ajNVdqHZLLA3WJ6DSZUfiakYe37PKnQhBIeOQBO4czqrnZDS79FH5Wm5m4X69TBicnHFlhiafvDwklOpZeXYQQ2icg/0]]></Avatar>
    <Alias><![CDATA[zhangsan]]></Alias>
    <Telephone><![CDATA[020-111111]]></Telephone>
    <ExtAttr>
        <Item>
        <Name><![CDATA[爱好]]></Name>
        <Type>0</Type>
        <Text>
            <Value><![CDATA[旅游]]></Value>
        </Text>
        </Item>
        <Item>
        <Name><![CDATA[卡号]]></Name>
        <Type>1</Type>
        <Web>
            <Title><![CDATA[企业微信]]></Title>
            <Url><![CDATA[https://work.weixin.qq.com]]></Url>
        </Web>
        </Item>
    </ExtAttr>
</xml>
';

$array = Xml::xml2array($xml);
$xml = Xml::array2xml($array);

print_r($xml);
