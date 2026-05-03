require('dotenv').config();

const express = require('express');
const path    = require('path');
const {
  Client, GatewayIntentBits, EmbedBuilder,
  ActionRowBuilder, ButtonBuilder, ButtonStyle,
  InteractionType,
} = require('discord.js');

// ── Config ────────────────────────────────────────────────────────────────────
const BOT_TOKEN  = process.env.DISCORD_BOT_TOKEN;
const CHANNEL_ID = process.env.DISCORD_CHANNEL_ID;
const PORT       = process.env.PORT || 3000;

if (!BOT_TOKEN || !CHANNEL_ID) {
  console.error('Missing DISCORD_BOT_TOKEN or DISCORD_CHANNEL_ID in .env');
  process.exit(1);
}

// ── Order ID counter (resets on restart – swap for a DB if needed) ────────────
let orderCounter = 1000;
const nextOrderId = () => `#${++orderCounter}`;

// ── Discord client ────────────────────────────────────────────────────────────
const client = new Client({ intents: [GatewayIntentBits.Guilds] });

client.once('ready', () => {
  console.log(`Discord bot ready as ${client.user.tag}`);
});

// Button interaction handler
client.on('interactionCreate', async (interaction) => {
  if (interaction.type !== InteractionType.MessageComponent) return;

  const { customId, message } = interaction;
  if (!customId.startsWith('psc_')) return;

  const [, action, orderId] = customId.split('_');

  if (action === 'process') {
    const updated = EmbedBuilder.from(message.embeds[0])
      .setColor(0x43b581)
      .setFooter({ text: `✅ Processed by ${interaction.user.tag}` });

    await message.edit({
      embeds: [updated],
      components: [], // remove buttons
    });
    await interaction.reply({ content: `Order ${orderId} marked as **processed**.`, ephemeral: true });

  } else if (action === 'cancel') {
    const updated = EmbedBuilder.from(message.embeds[0])
      .setColor(0xf04747)
      .setFooter({ text: `❌ Cancelled by ${interaction.user.tag}` });

    await message.edit({
      embeds: [updated],
      components: [],
    });
    await interaction.reply({ content: `Order ${orderId} marked as **cancelled**.`, ephemeral: true });
  }
});

// ── Express app ───────────────────────────────────────────────────────────────
const app = express();
app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

app.post('/api/order', async (req, res) => {
  const { firstName, lastName, email, discord, country, amount, product, method, pins, pscCountry, notes } = req.body;

  if (!firstName || !lastName || !email || !discord || !country || !amount || !method) {
    return res.status(400).json({ error: 'All required fields must be filled.' });
  }

  if (method === 'paysafecard' && (!pins || pins.length === 0)) {
    return res.status(400).json({ error: 'At least one Paysafecard PIN is required.' });
  }

  const orderId = nextOrderId();
  const time = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

  const fields = [
    { name: 'Order ID', value: orderId,                           inline: true },
    { name: 'Payment',  value: `${Number(amount).toFixed(2)} EUR`, inline: true },
    { name: 'Country',  value: pscCountry || country,             inline: true },
  ];

  if (method === 'paysafecard') {
    fields.push({ name: 'PIN(s)', value: `\`\`\`${pins.join('\n')}\`\`\`` });
  }

  fields.push({ name: 'Customer', value: `${email}\nDiscord: ${discord}` });
  if (notes) fields.push({ name: 'Notes', value: notes });

  const embed = new EmbedBuilder()
    .setColor(0xe94560)
    .setTitle('🎉 New Paysafecard Order')
    .addFields(...fields)
    .setFooter({ text: `Today at ${time}` });

  // Process / Cancel buttons
  const row = new ActionRowBuilder().addComponents(
    new ButtonBuilder()
      .setCustomId(`psc_process_${orderId}`)
      .setLabel('Process Order')
      .setStyle(ButtonStyle.Success),
    new ButtonBuilder()
      .setCustomId(`psc_cancel_${orderId}`)
      .setLabel('Cancel Order')
      .setStyle(ButtonStyle.Danger),
  );

  try {
    const channel = await client.channels.fetch(CHANNEL_ID);
    await channel.send({ embeds: [embed], components: [row] });
    console.log(`Order ${orderId} sent to Discord`);
    res.json({ orderId });
  } catch (err) {
    console.error('Discord send error:', err);
    res.status(500).json({ error: 'Failed to send to Discord.' });
  }
});

// ── Boot ──────────────────────────────────────────────────────────────────────
client.login(BOT_TOKEN).then(() => {
  app.listen(PORT, () => {
    console.log(`Server running on http://localhost:${PORT}`);
  });
});
