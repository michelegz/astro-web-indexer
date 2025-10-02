FROM python:3.9-alpine

# Install system dependencies required to compile Python packages
RUN apk add --no-cache build-base

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
COPY docker/python/reindex_mariadb.py .
COPY docker/python/watch_fits.py .

# Set the PATH to use the virtual environment's python
ENV PATH="/opt/venv/bin:$PATH"

# Startup command
CMD ["sh", "-c", "python reindex_mariadb.py /var/fits && python watch_fits.py /var/fits"]
