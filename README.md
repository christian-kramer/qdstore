# qdstore
Sharded Redundant RESTful Filesystem

Inspired by Ceph

<hr>

# Description
qdstore is designed as an easy way to achieve high availability using only HTTP GET/POST requests between servers.

# Operation
Data can be stored on 26 Shards, lettered a-z. Shards only communicate to applications via Gateways, numbered 0-infinity. Applications may use any Gateway for transactions; no data is stored on Gateways. Shards are chosen for writes by picking a "group" of two shards to write to, e.g. "ab". This "group" is POSTed along with the data to a Gateway, which will pass the data to the second Shard in the group. The second Shard will write the data to itself and give it a unique ID, the first two letters being the "group". The second Shard will pass the unique ID and data to the first Shard, which will write the data to itself. The second Shard will give the unique ID back to the Gateway, which will pass it back to the application. This unique ID will be how the application references the data later.

Diagram:

![diagram](https://i.imgur.com/nKiJyhe.jpg)

# Example Write:

Gateway: http://storage0.qdl.ink

Application: https://qdl.ink

https://qdl.ink GETs http://storage0.qdl.ink/group and gets 'bz'

https://qdl.ink POSTs {"items":["eggs", "milk"]} to http://storage0.qdl.ink/create?lists&bz and gets 'bza'


# Example Read:

https://qdl.ink GETs http://storage0.qdl.ink/read?lists&bza and gets {"items":["eggs", "milk"]}


# Example Update:

https://qdl.ink POSTs {"items":["eggs", "milk", "bacon"]} to http://storage0.qdl.ink/create?lists&bz and gets {"items":["eggs", "milk", "bacon"]}



