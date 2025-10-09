import ephem
import datetime
import math

def get_moon_ephemeris(timestamp):
    """
    Calculates moon phase percentage and phase angle for a given timestamp.
    
    Args:
        timestamp (float): UNIX timestamp in UTC.
        
    Returns:
        tuple: A tuple containing (moon_phase_percent, moon_phase_angle_deg).
    """
    # Convert the UNIX timestamp to a datetime object in UTC
    date = datetime.datetime.fromtimestamp(timestamp, tz=datetime.timezone.utc)

    # Create a Sun and Moon object
    sun = ephem.Sun(date)
    moon = ephem.Moon(date)
    
    # Calculate the phase angle
    # This is the elongation of the Moon from the Sun
    elongation = ephem.separation(moon.pos, sun.pos)
    phase_angle_rad = math.pi - elongation
    
    # Calculate illumination percentage from phase angle
    # k = (1 + cos(i)) / 2
    illumination_percent = (1 + math.cos(phase_angle_rad)) / 2 * 100
    
    # Determine the phase angle in degrees (0-360)
    # This requires knowing if the moon is waxing or waning.
    # We can check the difference in longitude between the Moon and the Sun.
    moon_lon = moon.ra
    sun_lon = sun.ra
    
    angle_diff = moon_lon - sun_lon
    if angle_diff < 0:
        angle_diff += 2 * math.pi # Normalize to 0-2pi
        
    moon_phase_angle_deg = math.degrees(angle_diff)

    return (illumination_percent, moon_phase_angle_deg)
