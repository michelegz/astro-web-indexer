FROM python:3.9-alpine

# Install system dependencies required to compile Python packages
RUN apk add --no-cache build-base git

WORKDIR /opt/scripts

# Create a virtual environment
RUN python -m venv /opt/venv

# Copy the requirements file
COPY docker/python/requirements.txt .

# Install Python packages into the virtual environment
RUN /opt/venv/bin/pip install --no-cache-dir -r requirements.txt

# Copy and install the XISF library
RUN /opt/venv/bin/pip install git+https://github.com/sergio-dr/xisf.git

# Copy the application scripts
COPY docker/python/reindex.py .
COPY docker/python/watch_fs.py .

# Set the PATH to use the virtual environment's python
ENV PATH="/opt/venv/bin:$PATH"

# Startup command
CMD ["sh", "-c", "python reindex.py /var/fits && python watch_fs.py /var/fits"]
