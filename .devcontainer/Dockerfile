# Codespaces用の開発環境コンテナ
# ローカル開発環境と同じようにDockerコンテナを起動できるUbuntu環境
FROM ubuntu:24.04

# 基本的なツールとDockerのインストール
RUN apt-get update && \
    apt-get install -y \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    git \
    make \
    wget \
    sudo \
    vim \
    && rm -rf /var/lib/apt/lists/*

# Dockerのインストール
RUN install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg && \
    chmod a+r /etc/apt/keyrings/docker.gpg && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    apt-get update && \
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin && \
    rm -rf /var/lib/apt/lists/*

# mkcertのインストール
RUN wget -q https://github.com/FiloSottile/mkcert/releases/download/v1.4.4/mkcert-v1.4.4-linux-amd64 && \
    chmod +x mkcert-v1.4.4-linux-amd64 && \
    mv mkcert-v1.4.4-linux-amd64 /usr/local/bin/mkcert

# ubuntuユーザーにsudo権限を付与（パスワード不要）
RUN echo "ubuntu ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/ubuntu && \
    chmod 0440 /etc/sudoers.d/ubuntu

WORKDIR /workspaces
