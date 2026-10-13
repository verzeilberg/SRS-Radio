import time
import sys
import board
import neopixel
import colorsys
import random

# Number of LEDs
NUM_LEDS = 141
LED_PIN = board.D18

# Create NeoPixel object
pixels = neopixel.NeoPixel(
    LED_PIN, NUM_LEDS, brightness=1.0, auto_write=False, pixel_order=neopixel.GRB
)

def rainbow_disco(duration, speed=0.1):
    """Rainbow disco effect for a specified duration in seconds."""
    start_time = time.time()

    while time.time() - start_time < duration:
        for i in range(NUM_LEDS):
            # Random hue for each LED
            hue = random.random()
            r, g, b = colorsys.hsv_to_rgb(hue, 1, 1)
            pixels[i] = (int(r * 255), int(g * 255), int(b * 255))
        pixels.show()
        time.sleep(speed)

    # Turn off LEDs when done
    pixels.fill((0, 0, 0))
    pixels.show()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 rainbow_disco.py <seconds>")
        sys.exit(1)

    try:
        duration = int(sys.argv[1])
    except ValueError:
        print("Please provide time in seconds as an integer.")
        sys.exit(1)

    try:
        print(f"Rainbow disco for {duration} seconds...")
        rainbow_disco(duration)
        print("Done, LEDs turned off.")
    except KeyboardInterrupt:
        pixels.fill((0, 0, 0))
        pixels.show()
        print("\nStopped, LEDs off.")
