import board
import neopixel

NUM_LEDS = 140
LED_PIN = board.D18

pixels = neopixel.NeoPixel(
    LED_PIN, NUM_LEDS, brightness=1.0, auto_write=False, pixel_order=neopixel.GRB
)

pixels.fill((0, 0, 0))
pixels.show()
