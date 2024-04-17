```text
#创建索引
PUT /indexNameNew
{
	"settings": {
		"number_of_shards": 8,
		"number_of_replicas": 1
	},
	"mappings": {
		"properties": {
			"commodity_id": {
				"type": "long"
			},
			"commodity_name": {
				"type": "text"
			},
			"picture_url": {
				"type": "keyword"
			},
			"price": {
				"type": "double"
			}
		}
	}
}

#为索引创建别名（类似软链接：一个别名可以链接多个索引，可以在重建索引过程中使用）
POST /_aliases
{
  "actions": [
    {
      "add": {
        "index": "ims_report_cycle_account_site_spu_day",
        "alias": "alias_ims_report_cycle_account_site_spu_day"
      }
    }
  ]
}

#删除别名
POST /_aliases
{
  "actions": [
    {
      "remove": {
        "index": "ims_report_cycle_account_site_spu_day",
        "alias": "alias_ims_report_cycle_account_site_spu_day"
      }
    }
  ]
}

#可以组合一起，删除并添加别名，相当于重命名，重新指向链接（新的索引重建好了，别名重新指向新的），有些版本不支持组合的，分开处理
POST /_aliases
{
  "actions": [
    {
      "add": {
        "index": "ims_report_cycle_account_site_spu_day_new",
        "alias": "alias_ims_report_cycle_account_site_spu_day"
      },
      "remove": {
        "index": "ims_report_cycle_account_site_spu_day",
        "alias": "alias_ims_report_cycle_account_site_spu_day"
      }
    }
  ]
}

#查看索引的别名，或者别名下的索引
GET /alias_ims_report_cycle_account_site_spu_day/_alias
GET /ims_report_cycle_account_site_spu_day/_alias


# 重建索引wait_for_completion=false后台处理
POST _reindex?slices=5&wait_for_completion=false
{
	"source": {
		"index": "ims_report_cycle_account_site_spu_day",
		"query": {
			"match": {
				"dayTime": 1691424000
			}
		},
		"size": 2000
	},
	"dest": {
		"index": "ims_report_cycle_account_site_spu_day_new"
	}
}


POST _reindex
{
  "source": {
    "index": "old-index",
    "query": {
      "match": {
        "field": "condition"
      }
    }
  },
  "dest": {
    "index": "new-index"
  }
}

#查询
GET ims_auto_delete_test_index/_search
{
  "query": {
    "term": {
      "accountCode": {
        "value": "B"
      }
    }
  },
  "size":10
}


#新增, （/_doc/1 使用自定义主键）
POST /ims_auto_delete_test_index/_doc
{
  "status": 1,
  "accountCode": "B"
}


#更新
POST /ims_auto_delete_test_index/1/_update
{
  "doc": {
    "name": "jiaqiangban gaolujie yagao"
  }
}


# 删除文档
POST ims_report_cycle_account_site_spu_day/_delete_by_query
{
  "query": {
    "range": {
      "dayTime": {
        "lt": 1693497600
      }
    }
  }
}

# 清空文档
POST ims_report_cycle_account_site_spu_day/_delete_by_query
{
  "query": {
    "match_all": {}
  }
}

#如果对同一个index/type/id 使用 PUT，后面的数据会覆盖前面的数据（save操作）
PUT /ims_auto_delete_test_index/_doc/1
{
  "accountCode": "test",
  "accountName": "市"
}

## 查询索引结构
GET /ims_auto_delete_test_index/_mapping


# 统计条数
GET  ims_auto_delete_test_index/_count
{
}

GET ims_auto_delete_test_index/_count
{
  "query": {
    "range": {
      "dayTime": {
        "lt": 1693497600
      }
    }
  }
}

# 分析
POST /_analyze
{
  "analyzer": "standard",
  "text": "我们看下实际的重量，然后重新更新一下这些数据要不然 我们后续的数据都有问题的"
}

#批量更新
POST /_bulk
{"delete":{"_index":"test-index", "_type":"test-type", "_id":"1"}}
{"create":{"_index":"test-index", "_type":"test-type", "_id":"2"}}
{"test_field":"test2"}
{"index":{"_index":"test-index", "_type":"test-type", "_id":"1"}}
{"test_field":"test1"}
{"update":{"_index":"test-index", "_type":"test-type", "_id":"3", "_retry_on_conflict":"3"}}
{"doc":{"test_field":"bulk filed 3"}}

# 有哪些类型的操作可以执行:
#（1）delete：删除一个文档，只要1个json串就可以了
#（2）create：PUT /index/type/id/_create；只创建新文档
#（3）index：普通的put操作，可以是创建文档，也可以是全量替换文档
#（4）update：执行的partial update操作，即 post 更新


GET /_search
{
  "query": {
    "fuzzy": {
      "name": "Accha"
    }
  }
}

# 查看集群配置，ILM周期检查时间默认是10分钟检查一次
GET /_cluster/settings

# 修改检查策略命令
PUT /_cluster/settings
{
  "transient": {
    "indices.lifecycle.poll_interval": "1m"
  }
}





// 5、如何仅保存最近100天的数据？
// 有了上面的认知，仅保存近100天的数据任务分解为：
// 1）delete_by_query设置检索近100天数据；
// 2）执行forcemerge操作，手动释放磁盘空间。
// 删除脚本如下：

#!/bin/sh
curl -H 'Content-Type:application/json' -d'
{
    "query": {
        "range": {
            "pt": {
                "lt": "now-100d",
                "format": "epoch_millis"
            }
        }
    }
}
' -X POST "http://192.168.1.101:9200/logstash_*/_delete_by_query?conflicts=proceed"

// merge脚本如下：
#!/bin/sh
curl -XPOST 'http://192.168.1.101:9200/_forcemerge?
only_expunge_deletes=true&max_num_segments=1'








```