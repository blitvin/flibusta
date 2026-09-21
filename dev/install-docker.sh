#!/usr/bin/env bash
# Installs Docker Engine + the compose plugin, and the NFS client bits.
# Needs sudo. Run once; re-running is harmless.
#
# Linux Mint note: Mint's own codename (virginia, vanessa, ...) does not exist
# in Docker's apt repository. The Ubuntu base codename from UBUNTU_CODENAME is
# what must go into the sources entry - this is the single most common reason
# "apt-get update" fails right after adding the repo.
set -euo pipefail

. /etc/os-release
CODENAME="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
if [ -z "$CODENAME" ]; then
    echo "Cannot determine the Ubuntu codename from /etc/os-release." >&2
    exit 1
fi
echo "==> Using Docker repository suite: $CODENAME (distro reports ${VERSION_CODENAME:-?})"

echo "==> Prerequisites"
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg nfs-common

echo "==> Docker apt repository"
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
    | sudo gpg --batch --yes --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $CODENAME stable" \
    | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

echo "==> Docker Engine"
sudo apt-get update
sudo apt-get install -y \
    docker-ce docker-ce-cli containerd.io \
    docker-buildx-plugin docker-compose-plugin

echo "==> Adding $USER to the docker group"
sudo usermod -aG docker "$USER"

cat <<EOF

Done. Docker is installed:
  $(docker --version 2>/dev/null || echo '(run as root until you re-login)')

One more step: group membership is only picked up by new logins. Either log out
and back in, or start a shell with the new group for this session:

    newgrp docker

Then verify without sudo:

    docker run --rm hello-world

Note that VSCode must also be restarted from a session that has the new group,
or its Docker extension will keep reporting permission denied.
EOF
