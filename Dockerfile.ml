FROM sail-8.5/app

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       python3-pip \
       python3-venv \
    && rm -rf /var/lib/apt/lists/*

RUN python3 -m venv /opt/ml-venv

ENV PATH="/opt/ml-venv/bin:${PATH}"

COPY requirements-ml.txt /tmp/requirements-ml.txt

RUN pip install --no-cache-dir -r /tmp/requirements-ml.txt