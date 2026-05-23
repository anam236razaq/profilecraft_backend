-- ================================================
-- ProfileCraft Database Schema
-- =============================================

-- --------------------------------------------------------
-- Add plan column to users table
-- --------------------------------------------------------
ALTER TABLE users
    ADD COLUMN plan VARCHAR(50) DEFAULT 'basic' AFTER is_admin;