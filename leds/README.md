# LED strip

NeoPixel strip (GRB) on GPIO 18 of the Raspberry Pi. The scripts need root for GPIO access.

## Jira alarm

`radio:start` runs `red_leds.py <seconds>` while the alarm plays when `ALARM_LEDS_ENABLED=1`
(set it in `.env.local` on the Pi). The script turns the strip off when the time is up.

One-time setup on the Pi:

```bash
sudo pip3 install --break-system-packages adafruit-circuitpython-neopixel
echo 'srs ALL=(root) NOPASSWD: /usr/bin/python3 /var/www/srs-radio/leds/red_leds.py *' \
  | sudo tee /etc/sudoers.d/srs-radio-leds
sudo chmod 440 /etc/sudoers.d/srs-radio-leds
sudo visudo -c
```

Test: `sudo -u srs sudo -n /usr/bin/python3 /var/www/srs-radio/leds/red_leds.py 5`

## Scripts

| Script | Usage |
|---|---|
| `red_leds.py [seconds]` | Red breathing, endless without argument |
| `rainbow_leds.py <seconds>` | Rainbow breathing |
| `disco_leds.py <seconds>` | Random colours |
| `christmas_leds.py <seconds>` | Red/green/blue/white chase |
| `sinterklaas_leds.py <seconds>` | Red/white alternating |
| `startup_leds.py` | 2-minute rainbow pulse |
| `led_off.py` | All LEDs off |
