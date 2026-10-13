import time
import sys
import board
import neopixel
import math

# Number of LEDs
NUM_LEDS = 141
LED_PIN = board.D18

# Create NeoPixel object
pixels = neopixel.NeoPixel(
    LED_PIN, NUM_LEDS, brightness=1.0, auto_write=False, pixel_order=neopixel.GRB
)

def breathe(duration=None, speed=0.05):
    """Breathing red effect. duration=None means run forever."""
    start_time = time.time()
    t = 0
    while True:
        # Stop if duration was given and exceeded
        if duration is not None and time.time() - start_time >= duration:
            break

        # Sine wave breathing (0 → 255 → 0)
        brightness = (math.sin(t) + 1) / 2  
        red_value = int(255 * brightness)

        pixels.fill((red_value, 0, 0))
        pixels.show()

        t += 0.1
        time.sleep(speed)

    # Turn LEDs off when done
    pixels.fill((0, 0, 0))
    pixels.show()

if __name__ == "__main__":
    try:
        if len(sys.argv) > 1:
            try:
                duration = int(sys.argv[1])
                print(f"Breathing red for {duration} seconds...")
                breathe(duration=duration)
            except ValueError:
                print("Please provide time in seconds as an integer.")
        else:
            print("Breathing red endlessly... Press CTRL+C to stop.")
            breathe()
    except KeyboardInterrupt:
        pixels.fill((0, 0, 0))
        pixels.show()
        print("\nStopped, LEDs off.")
