import time
import sys
import board
import neopixel

# Number of LEDs
NUM_LEDS = 141
LED_PIN = board.D18

# Create NeoPixel object
pixels = neopixel.NeoPixel(
    LED_PIN, NUM_LEDS, brightness=1.0, auto_write=False, pixel_order=neopixel.GRB
)

# Christmas colors: Red, Green, Blue, White
CHRISTMAS_COLORS = [
    (255, 0, 0),    # Red
    (0, 255, 0),    # Green
    (0, 0, 255),    # Blue
    (255, 255, 255) # White
]

def christmas_pattern(duration, speed=0.5):
    """Alternating Christmas colors for a specified duration in seconds."""
    start_time = time.time()
    shift = 0

    while time.time() - start_time < duration:
        for i in range(NUM_LEDS):
            # Cycle through Christmas colors
            color_index = (i + shift) % len(CHRISTMAS_COLORS)
            pixels[i] = CHRISTMAS_COLORS[color_index]
        pixels.show()
        time.sleep(speed)
        shift += 1  # Shift pattern for moving effect

    # Turn off LEDs when done
    pixels.fill((0, 0, 0))
    pixels.show()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 christmas_leds.py <seconds>")
        sys.exit(1)

    try:
        duration = int(sys.argv[1])
    except ValueError:
        print("Please provide time in seconds as an integer.")
        sys.exit(1)

    try:
        print(f"Christmas pattern for {duration} seconds...")
        christmas_pattern(duration)
        print("Done, LEDs turned off.")
    except KeyboardInterrupt:
        pixels.fill((0, 0, 0))
        pixels.show()

