#!/bin/bash
set -e

echo "Installing Oracle Instant Client and enabling OCI8..."

sudo apt-get update
sudo apt-get install -y libaio1t64 wget unzip  # ← Changed to libaio1t64

# These two lines are the only place to update for new versions
INSTANT_CLIENT_VERSION=${INSTANT_CLIENT_VERSION:-23.26.0.0.0}
INSTANT_CLIENT_DIR=${INSTANT_CLIENT_DIR:-2326000}

BASIC_ZIP="instantclient-basic-linux.x64-${INSTANT_CLIENT_VERSION}.zip"
SDK_ZIP="instantclient-sdk-linux.x64-${INSTANT_CLIENT_VERSION}.zip"

wget -q https://download.oracle.com/otn_software/linux/instantclient/${INSTANT_CLIENT_DIR}/${BASIC_ZIP}
wget -q https://download.oracle.com/otn_software/linux/instantclient/${INSTANT_CLIENT_DIR}/${SDK_ZIP}

sudo mkdir -p /opt/oracle
sudo unzip -q ${BASIC_ZIP} -d /opt/oracle
sudo unzip -q ${SDK_ZIP} -d /opt/oracle
sudo mv /opt/oracle/instantclient_* /opt/oracle/instantclient

# Create compatibility symlink for old SONAME
sudo ln -sf /usr/lib/x86_64-linux-gnu/libaio.so.1t64 /usr/lib/x86_64-linux-gnu/libaio.so.1

sudo sh -c "echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf"
sudo ldconfig

echo "Oracle Instant Client ${INSTANT_CLIENT_VERSION} installed (with libaio compatibility)"