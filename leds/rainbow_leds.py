import time
import sys
import board
import neopixel
import math
import colorsys

# Number of LEDs
NUM_LEDS = 141
LED_PIN = board.D18

# Create NeoPixel object
pixels = neopixel.NeoPixel(
    LED_PIN, NUM_LEDS, brightness=1.0, auto_write=False, pixel_order=neopixel.GRB
)

def rainbow_breathe(duration, speed=0.05):
    """Rainbow breathing effect for a specified duration in seconds."""
    start_time = time.time()
    t = 0

    while time.time() - start_time < duration:
        # Sine wave for brightness (0 → 1 → 0)
        brightness = (math.sin(t) + 1) / 2  

        for i in range(NUM_LEDS):
            # Calculate hue for rainbow effect, cycling across strip
            hue = (i / NUM_LEDS + t / 20) % 1.0
            r, g, b = colorsys.hsv_to_rgb(hue, 1, brightness)
            pixels[i] = (int(r * 255), int(g * 255), int(b * 255))

        pixels.show()
        t += 0.1
        time.sleep(speed)

    # Turn off LEDs when done
    pixels.fill((0, 0, 0))
    pixels.show()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 rainbow_breath.py <seconds>")
        sys.exit(1)

    try:
        duration = int(sys.argv[1])
    except ValueError:
        print("Please provide time in seconds as an integer.")
        sys.exit(1)

    try:
        print(f"Rainbow breathing for {duration} seconds...")
        rainbow_breathe(duration)
        print("Done, LEDs turned off.")
    except KeyboardInterrupt:
        pixels.fill((0, 0, 0))
        pixels.show()
        print("\nStopped, LEDs off.")
