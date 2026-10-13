import time
import board
import neopixel
import math
import colorsys

NUM_LEDS = 146
LED_PIN = board.D18

pixels = neopixel.NeoPixel(
    LED_PIN, NUM_LEDS, brightness=0.5, auto_write=False, pixel_order=neopixel.GRB
)

def rainbow_pulse(duration=10, speed=0.05):
    """Simple rainbow pulse effect for startup."""
    start_time = time.time()
    t = 0

    while time.time() - start_time < duration:
        brightness = (math.sin(t) + 1) / 2  # 0-1 sine wave
        for i in range(NUM_LEDS):
            hue = (i / NUM_LEDS + t / 10) % 1.0
            r, g, b = colorsys.hsv_to_rgb(hue, 1, brightness)
            pixels[i] = (int(r * 255), int(g * 255), int(b * 255))
        pixels.show()
        t += 0.1
        time.sleep(speed)

    pixels.fill((0, 0, 0))
    pixels.show()

if __name__ == "__main__":
    rainbow_pulse(duration=120)  # Run for 15 seconds at boot
