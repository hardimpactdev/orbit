//! Native Node identity shared by enrollment and Doctor.
use std::{ffi::CStr, net::Ipv4Addr};

pub fn platform() -> std::io::Result<(String, String)> {
    // SAFETY: uname fills the live utsname allocation with NUL-terminated fields.
    let mut value: libc::utsname = unsafe { std::mem::zeroed() };
    if unsafe { libc::uname(&mut value) } != 0 {
        return Err(std::io::Error::last_os_error());
    }
    let platform = unsafe { CStr::from_ptr(value.sysname.as_ptr()) }
        .to_string_lossy()
        .to_lowercase();
    let architecture = unsafe { CStr::from_ptr(value.machine.as_ptr()) }
        .to_string_lossy()
        .into_owned();
    Ok((platform, architecture))
}

pub fn addresses() -> std::io::Result<Vec<Ipv4Addr>> {
    let mut first: *mut libc::ifaddrs = std::ptr::null_mut();
    // SAFETY: getifaddrs allocates a linked list and writes its head to `first`.
    if unsafe { libc::getifaddrs(&mut first) } != 0 {
        return Err(std::io::Error::last_os_error());
    }
    let mut addresses = vec![];
    let mut current = first;
    // SAFETY: list nodes and sockaddr values live until freeifaddrs. Check family before casting.
    unsafe {
        while !current.is_null() {
            let address = (*current).ifa_addr;
            if !address.is_null() && (*address).sa_family as i32 == libc::AF_INET {
                addresses.push(Ipv4Addr::from(
                    (*(address as *const libc::sockaddr_in))
                        .sin_addr
                        .s_addr
                        .to_ne_bytes(),
                ));
            }
            current = (*current).ifa_next;
        }
        libc::freeifaddrs(first);
    }
    Ok(addresses)
}
