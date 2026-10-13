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

def red_white_pattern(duration, speed=0.5):
    """Red and white alternating pattern for a specified duration in seconds."""
    start_time = time.time()
    toggle = False

    while time.time() - start_time < duration:
        for i in range(NUM_LEDS):
            if (i + toggle) % 2 == 0:
                pixels[i] = (255, 0, 0)   # Red
            else:
                pixels[i] = (255, 255, 255)  # White
        pixels.show()
        time.sleep(speed)
        toggle = not toggle  # Shift pattern each cycle for a "moving" effect

    # Turn off LEDs when done
    pixels.fill((0, 0, 0))
    pixels.show()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 red_white_pattern.py <seconds>")
        sys.exit(1)

    try:
        duration = int(sys.argv[1])
    except ValueError:
        print("Please provide time in seconds as an integer.")
        sys.exit(1)

    try:
        print(f"Red & White pattern for {duration} seconds...")
        red_white_pattern(duration)
        print("Done, LEDs turned off.")
    except KeyboardInterrupt:
        pixels.fill((0, 0, 0))
        pixels.show()
