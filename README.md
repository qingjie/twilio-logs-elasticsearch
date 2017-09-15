# twilio-logs-elasticsearch
Export Twilio logs to Elasticsearch. An index template is also included for Elasticsearch. Use PostMan to PUT the 
template to http://localhost:9200/_template/twilio-logs.

## Options
    --eshost: http://localhost:9200
    [optional] --esauth: base64 encoded username:password
    [optional] --sentdate: defaults to -1 day
    --twiliosid: Twilio Account SID
    --twiliotoken: Twilio Token