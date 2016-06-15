#!/usr/bin/env bash
# First argument of this script should be the password of the cloudscalers user on the machine hosting the docker WHMCS container
# Second argument is of format username@cloudspace_ip
# Third argument is the SSH port of the virtual machine
# Fourth argument is the name of the Docker container WHMCS is running on
set -euf
rootpassword=$1
remote_host=$2
remote_ssh_port=$3
container_name=$4
tar -cvzf cockpit.tgz whmcs
scp -P ${remote_ssh_port} cockpit.tgz ${remote_host}:/tmp/
scp -P ${remote_ssh_port} docker-deploy-yves.sh ${remote_host}:/tmp/docker-deploy.sh
ssh -p ${remote_ssh_port} -t ${remote_host} "echo $rootpassword | sudo -S -i bash /tmp/docker-deploy.sh ${container_name}"
rm -f cockpit.tgz
