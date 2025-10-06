import numpy as np

def median_and_mad(data):
    """
    Calculate median and MAD (median absolute deviation).
    """
    flat = data.ravel()
    med = np.median(flat)
    mad = np.median(np.abs(flat - med))
    return med, mad

def mtf(m, x):
    """
    Midtones Transfer Function:
    y = ((m-1)*x) / ((2*m - 1)*x - m)
    """
    num = (m - 1.0) * x
    den = (2.0 * m - 1.0) * x - m
    eps = 1e-12
    return num / (den + eps)

def find_m_for_target(x, y_target, m_min=0.01, m_max=50.0, tol=1e-9, max_iter=200):
    """
    Find m such that mtf(m, x) ≈ y_target (using bisection).
    """
    a, b = m_min, m_max
    fa = mtf(a, x) - y_target
    fb = mtf(b, x) - y_target
    if np.sign(fa) == np.sign(fb):
        return 0.5  # fallback: lineare
    for _ in range(max_iter):
        c = 0.5 * (a + b)
        fc = mtf(c, x) - y_target
        if abs(fc) < tol:
            return c
        if np.sign(fc) == np.sign(fa):
            a, fa = c, fc
        else:
            b, fb = c, fc
    return 0.5  # fallback

def normalize_channel(data, black_point=None, white_point=None):
    """
    Normalize to [0,1] using black/white points.
    """
    if black_point is None:
        black_point = data.min()
    if white_point is None:
        white_point = data.max()
    denom = (white_point - black_point)
    if denom == 0:
        denom = 1e-12
    return (data - black_point) / denom

def stf_autostretch(img,
                                     k_black=2.8,
                                     k_white=10.0,
                                     target_mid=0.25,
                                     clip_output=True):
    """
    img: array 2D numpy con dati lineari (float).
    Returns: stretched image and parameters used.
    """
    med, mad = median_and_mad(img)
    black = med - k_black * mad
    white = med + k_white * mad

    norm = normalize_channel(img, black_point=black, white_point=white)
    norm = np.clip(norm, 0.0, 1.0)

    med_norm = np.clip((med - black) / (white - black + 1e-12), 0.0, 1.0)
    m = find_m_for_target(med_norm, target_mid)

    stretched = mtf(m, norm)
    if clip_output:
        stretched = np.clip(stretched, 0.0, 1.0)

    params = {
        'median': med,
        'mad': mad,
        'black': black,
        'white': white,
        'med_norm': med_norm,
        'm': m
    }
    return stretched, params
