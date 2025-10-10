import ephem
import datetime
import math

def get_moon_ephemeris(timestamp):
    """
    Calculates the Moon's illumination percentage and phase angle
    for a given UNIX timestamp.
    
    Args:
        timestamp (float): UNIX timestamp in UTC.
        
    Returns:
        tuple: (illumination_percent, moon_phase_angle_deg)
        """
    # Convert UNIX timestamp to a timezone-aware datetime in UTC
    date = datetime.datetime.fromtimestamp(timestamp, tz=datetime.timezone.utc)

    # Create Sun and Moon objects
    sun = ephem.Sun()
    moon = ephem.Moon()

    # Compute their positions for the given date as seen from Earth
    sun.compute(date)
    moon.compute(date)
    
    # Use the library's direct calculation for illumination percentage (reliable)
    illumination_percent = moon.phase

    # Calculate the phase angle in degrees (0-360) for emoji selection.
    # This is based on the difference in their Right Ascension (.ra).
    angle_diff_rad = moon.ra - sun.ra
    
    # Normalize angle to be in the range [0, 2*pi]
    if angle_diff_rad < 0:
        angle_diff_rad += 2 * math.pi
        
    moon_phase_angle_deg = math.degrees(angle_diff_rad)

    return illumination_percent, moon_phase_angle_deg
