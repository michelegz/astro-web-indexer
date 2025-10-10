import numpy as np
from PIL import Image
from io import BytesIO
import logging
from .stretch import stf_autostretch_color

logger = logging.getLogger('reindex.image_processing')

def make_thumbnail(data, size):
    try:
        # Ensure data is float32 and clean
        data = np.nan_to_num(data).astype(np.float32)
        
        # Apply the STF Autostretch (works for both mono and color)
        stretched, _ = stf_autostretch_color(data)
        
        # Convert to 8-bit image for display
        img = (stretched * 255).astype(np.uint8)
        
        # Create thumbnail with Pillow
        image = Image.fromarray(img)
        image.thumbnail(size)
        buf = BytesIO()
        image.save(buf, format='PNG')
        return buf.getvalue()
    except Exception as e:
        logger.warning(f"Thumbnail generation failed: {e}")
        return None

def make_crop_preview(data, size):
    try:
        # This function now expects 2D monochrome data for simplicity and robustness
        data = np.nan_to_num(data).astype(np.float32)
        
        # Get original dimensions, handling both mono (H, W) and color (H, W, C)
        if data.ndim == 2:
            h, w = data.shape
        elif data.ndim == 3 and data.shape[2] in [3, 4]:
            h, w, channels = data.shape
        else:
            logger.warning(f"Unsupported data shape for crop preview: {data.shape}")
            return None

        max_w, max_h = size

        if w <= max_w and h <= max_h:
            cropped_data = data
        else:
            original_aspect = w / h
            max_aspect = max_w / max_h
            if original_aspect > max_aspect:
                crop_w = max_w
                crop_h = int(crop_w / original_aspect)
            else:
                crop_h = max_h
                crop_w = int(crop_h * original_aspect)
            
            center_x, center_y = w // 2, h // 2
            start_x = center_x - crop_w // 2
            start_y = center_y - crop_h // 2
            
            if data.ndim == 2:
                cropped_data = data[start_y : start_y + crop_h, start_x : start_x + crop_w]
            else:
                cropped_data = data[start_y : start_y + crop_h, start_x : start_x + crop_w, :]

        stretched, _ = stf_autostretch_color(cropped_data)
        img = (stretched * 255).astype(np.uint8)
        image = Image.fromarray(img)
        buf = BytesIO()
        image.save(buf, format='PNG')
        return buf.getvalue()
    except Exception as e:
        logger.warning(f"Crop preview generation failed: {e}")
        return None
