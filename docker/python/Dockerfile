FROM python:3.9-slim-bookworm

# NOTE: slim (glibc) instead of alpine (musl) on purpose: scientific wheels
# (numpy, astropy, sep) ship manylinux binaries which do not install on musl.
# No compiler toolchain needed as long as all requirements have wheels.

WORKDIR /opt/scripts

# Create a virtual environment
RUN python -m venv /opt/venv

# Copy the requirements file
COPY docker/python/requirements.txt .

# Install Python packages into the virtual environment
RUN /opt/venv/bin/pip install --no-cache-dir -r requirements.txt

# Copy and install the local XISF library
COPY external/xisf /opt/scripts/external/xisf
RUN /opt/venv/bin/pip install /opt/scripts/external/xisf

# Copy the application scripts
COPY docker/python/reindex.py .
COPY docker/python/watch_fs.py .
COPY docker/python/indexer_lib ./indexer_lib/

# Set the PATH to use the virtual environment's python
ENV PATH="/opt/venv/bin:$PATH"

# Unbuffered stdout/stderr: without a TTY Python block-buffers its output and
# `docker logs` stays empty until the buffer fills. Applies to image builds.
ENV PYTHONUNBUFFERED=1

# Startup command
CMD ["sh", "-c", "python reindex.py /var/fits && python watch_fs.py /var/fits"]
